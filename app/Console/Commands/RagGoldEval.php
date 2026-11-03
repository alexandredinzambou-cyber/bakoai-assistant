<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantService;
use App\Services\Assistant\ContextAssembler;
use App\Services\Evaluation\CachedQueryEmbeddingService;
use App\Services\Evaluation\EvalRunMetadata;
use App\Services\Evaluation\EvalStatistics;
use App\Services\Evaluation\GoldEvidence;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Retrieval benchmark on the versioned gold set (database/seeders/eval_gold.json).
 *
 * Only the first --top-k chunks count: rows that retrieval appends after the cut (bridged
 * neighbours) are not scored, so two configurations are compared on the same number of chunks.
 * With --stage=context the chunks scored are the ones ContextAssembler hands to the generator on
 * /api/mistral-raw/ask, at the configured top_k: what the model actually reads.
 */
class RagGoldEval extends Command
{
    protected $signature = 'rag:gold-eval
        {--file=database/seeders/eval_gold.json : Versioned gold dataset}
        {--split=dev : dev, test or all (tune on dev, report on test)}
        {--top-k=10 : Strict cut: only the first k chunks are scored}
        {--mode=raw : raw (mistral-raw query) or assistant (/assistant query rewrite and link filter)}
        {--stage=ranked : ranked (top-k of the ranking) or context (chunks the generator receives)}
        {--baseline= : Previous rag:gold-eval JSON report, compared case by case (McNemar)}
        {--format=both : markdown, json or both}
        {--no-cache : Embed the queries again instead of reading the evaluation cache}
        {--check-dataset : Only check that every evidence group still matches the active corpus}';

    protected $description = 'Benchmark retrieval on the versioned gold set: strict top-k coverage, MRR, nDCG, with run metadata.';

    public function handle(EvalRunMetadata $metadata): int
    {
        $file = base_path((string) $this->option('file'));
        $split = (string) $this->option('split');
        $mode = (string) $this->option('mode');
        $stage = (string) $this->option('stage');
        $format = (string) $this->option('format');
        $topK = max(1, (int) $this->option('top-k'));

        if (! in_array($split, ['dev', 'test', 'all'], true)
            || ! in_array($mode, ['raw', 'assistant'], true)
            || ! in_array($stage, ['ranked', 'context'], true)
            || ! in_array($format, ['markdown', 'json', 'both'], true)) {
            $this->error('Invalid option: --split=dev|test|all, --mode=raw|assistant, --stage=ranked|context, --format=markdown|json|both.');

            return self::INVALID;
        }

        try {
            $dataset = json_decode((string) File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid gold dataset: '.$exception->getMessage());

            return self::INVALID;
        } catch (\Throwable) {
            $this->error("Gold dataset not found: {$file}");

            return self::INVALID;
        }

        $cases = array_values(array_filter(
            (array) ($dataset['cases'] ?? []),
            static fn (array $case): bool => ($case['must_evidence'] ?? []) !== []
                && ($split === 'all' || ($case['split'] ?? null) === $split),
        ));

        if ($this->option('check-dataset')) {
            return $this->checkDataset($cases);
        }

        app()->singleton(EmbeddingService::class, CachedQueryEmbeddingService::class);
        /** @var CachedQueryEmbeddingService $embedding */
        $embedding = app(EmbeddingService::class);
        $embedding->readCache = ! $this->option('no-cache');
        $retrieval = app(RetrievalService::class);
        $assistant = $mode === 'assistant' ? app(AssistantService::class) : null;
        $rows = [];

        foreach ($cases as $case) {
            $query = ['question' => (string) $case['question'], 'operation' => null];

            if ($assistant !== null) {
                $query = $assistant->retrievalQueryFor((string) $case['question']);
            }

            $chunks = $this->retrieve($retrieval, $query, $stage, $topK, $mode);
            $score = GoldEvidence::score(
                $case['must_evidence'],
                $chunks->map(static fn (object $chunk): string => (string) $chunk->contenu)->all(),
            );

            $rows[] = [
                'id' => $case['id'],
                'split' => $case['split'] ?? null,
                'type' => $case['type'] ?? null,
                'style' => $case['style'] ?? null,
                'embedding_model' => $embedding->lastModel(),
                'rows' => $chunks->count(),
                'chars' => (int) $chunks->sum(static fn (object $chunk): int => mb_strlen((string) $chunk->contenu)),
                'chunk_ids' => $chunks->pluck('chunk_id')->map(static fn ($id): int => (int) $id)->all(),
                'documents' => $chunks->pluck('document_titre')->unique()->values()->all(),
            ] + $score;
        }

        $fallbacks = count($embedding->fallbackQueries());
        $validity = EvalRunMetadata::validity(count($rows), $fallbacks > 0 ? ['embedding_fallback' => $fallbacks] : []);
        // An embedding computed by a fallback model is searched against an index it was not built
        // with: even one such query distorts a small benchmark, so the threshold here is zero.
        $validity['valid'] = $validity['valid'] && $fallbacks === 0;
        $validity['threshold'] = 0.0;

        $report = [
            'kind' => 'rag-gold-eval',
            'metadata' => $metadata->collect($file, [
                'split' => $split,
                'mode' => $mode,
                'stage' => $stage,
                'top_k' => $stage === 'ranked' ? $topK : (int) config('rag.top_k', 5),
            ]),
            'validity' => $validity,
            'summary' => $this->summary($rows),
            'by_type' => collect($rows)->groupBy('type')->map(fn (Collection $group): array => $this->summary($group->all()))->all(),
            'comparison' => null,
            'rows' => $rows,
        ];

        if ($baseline = $this->option('baseline')) {
            $report['comparison'] = $this->compare((string) $baseline, $report);
        }

        $this->write($report, $format);

        return $validity['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{question: string, operation: ?string}  $query
     */
    private function retrieve(RetrievalService $retrieval, array $query, string $stage, int $topK, string $mode): Collection
    {
        if ($stage === 'context') {
            return (new ContextAssembler)->select(
                $this->officialOnly($retrieval->search($query['question'], 'PVIT', $query['operation']), $mode),
            )->values();
        }

        return $this->officialOnly($retrieval->search($query['question'], 'PVIT', $query['operation'], $topK), $mode)
            ->take($topK)
            ->values();
    }

    private function officialOnly(Collection $chunks, string $mode): Collection
    {
        if ($mode !== 'assistant') {
            return $chunks;
        }

        $hosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));

        // /assistant drops any chunk whose official link is not an https link on an allowed host.
        return $chunks->filter(static function (object $chunk) use ($hosts): bool {
            $url = trim((string) ($chunk->lien_officiel ?? ''));

            return str_starts_with($url, 'https://')
                && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true);
        })->values();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $n = count($rows);
        $full = count(array_filter($rows, static fn (array $row): bool => $row['full']));
        $hit = count(array_filter($rows, static fn (array $row): bool => $row['hit']));

        return [
            'cases' => $n,
            'full_coverage' => $n > 0 ? round($full / $n, 4) : null,
            'full_coverage_ci95' => EvalStatistics::wilson($full, $n),
            'group_recall' => EvalStatistics::mean(array_column($rows, 'group_recall')),
            'hit_rate' => $n > 0 ? round($hit / $n, 4) : null,
            'mrr' => EvalStatistics::mean(array_column($rows, 'reciprocal_rank')),
            'ndcg' => EvalStatistics::mean(array_column($rows, 'ndcg')),
            'evidence_precision' => EvalStatistics::mean(array_column($rows, 'evidence_precision')),
            'avg_rows' => EvalStatistics::mean(array_column($rows, 'rows')),
            'avg_chars' => EvalStatistics::mean(array_column($rows, 'chars')),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function compare(string $baselineFile, array $report): array
    {
        $path = is_file($baselineFile) ? $baselineFile : base_path($baselineFile);
        $baseline = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($baseline) || ($baseline['kind'] ?? null) !== 'rag-gold-eval') {
            $this->warn("Baseline ignored: {$baselineFile} is not a rag:gold-eval report.");

            return ['baseline' => $baselineFile, 'error' => 'not_a_gold_eval_report'];
        }

        // What differs between the two runs, so a reader knows what the comparison isolates.
        $differences = [];
        $before = $baseline['metadata'] ?? [];
        $after = $report['metadata'];

        foreach ([
            'dataset.sha1' => fn (array $m) => $m['dataset']['sha1'] ?? null,
            'split' => fn (array $m) => $m['split'] ?? null,
            'mode' => fn (array $m) => $m['mode'] ?? null,
            'stage' => fn (array $m) => $m['stage'] ?? null,
            'top_k' => fn (array $m) => $m['top_k'] ?? null,
            'git.commit' => fn (array $m) => $m['git']['commit'] ?? null,
            'knowledge_base.fingerprint' => fn (array $m) => $m['knowledge_base']['fingerprint'] ?? null,
            'embedding_model' => fn (array $m) => $m['embedding_model'] ?? null,
            'retrieval' => fn (array $m) => json_encode($m['retrieval'] ?? null),
        ] as $key => $read) {
            if ($read($before) !== $read($after)) {
                $differences[] = $key;
            }
        }

        $pass = static fn (array $rows): array => collect($rows)->mapWithKeys(
            static fn (array $row): array => [(string) $row['id'] => (bool) $row['full']],
        )->all();
        $paired = EvalStatistics::pairedComparison($pass($baseline['rows'] ?? []), $pass($report['rows']));

        return [
            'baseline' => $baselineFile,
            'baseline_valid' => (bool) ($baseline['validity']['valid'] ?? false),
            'differences' => $differences,
            'baseline_full_coverage' => $baseline['summary']['full_coverage'] ?? null,
            'full_coverage' => $report['summary']['full_coverage'],
        ] + $paired;
    }

    /**
     * Every evidence group must still be satisfiable by some active chunk: after a re-ingestion
     * a group that matches nothing makes its case unanswerable for retrieval, whatever the ranking.
     *
     * @param  list<array<string, mixed>>  $cases
     */
    private function checkDataset(array $cases): int
    {
        $texts = DB::table('chunks')
            ->join('documents_api', 'documents_api.id', '=', 'chunks.document_id')
            ->where('chunks.status', 'active')
            ->where('documents_api.actif', true)
            ->pluck('chunks.contenu')
            ->map(static fn ($text): string => GoldEvidence::normalize((string) $text))
            ->all();
        $broken = 0;
        $staleAlternatives = 0;

        foreach ($cases as $case) {
            foreach ($case['must_evidence'] as $groupIndex => $group) {
                $matched = 0;

                foreach ($group as $evidence) {
                    $found = false;

                    foreach ($texts as $text) {
                        if (GoldEvidence::matches($evidence, $text)) {
                            $found = true;

                            break;
                        }
                    }

                    if ($found) {
                        $matched++;
                    } else {
                        $staleAlternatives++;
                        $this->line("  stale alternative {$case['id']} group {$groupIndex}: ".json_encode($evidence, JSON_UNESCAPED_UNICODE));
                    }
                }

                if ($matched === 0) {
                    $broken++;
                    $this->error("Case {$case['id']}: evidence group {$groupIndex} matches no active chunk.");
                }
            }
        }

        $this->info(sprintf('%d cases checked against %d active chunks: %d broken groups, %d stale alternatives.', count($cases), count($texts), $broken, $staleAlternatives));

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function write(array $report, string $format): void
    {
        $meta = $report['metadata'];
        $summary = $report['summary'];
        File::ensureDirectoryExists(storage_path('app/eval-reports'));
        $base = storage_path(sprintf(
            'app/eval-reports/gold-eval-%s-%s-%s-k%d-%s',
            $meta['split'],
            $meta['mode'],
            $meta['stage'],
            $meta['top_k'],
            now()->format('Ymd-His'),
        ));

        if (in_array($format, ['json', 'both'], true)) {
            File::put($base.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info("Report written to {$base}.json");
        }

        if (in_array($format, ['markdown', 'both'], true)) {
            File::put($base.'.md', $this->markdown($report));
            $this->info("Report written to {$base}.md");
        }

        $ci = $summary['full_coverage_ci95'];
        $this->line(sprintf(
            '%s cases=%d full=%s [%s-%s] group_recall=%s mrr=%s ndcg=%s avg_rows=%s avg_chars=%s',
            $report['validity']['valid'] ? 'VALID' : 'INVALID',
            $summary['cases'],
            $this->percent($summary['full_coverage']),
            $this->percent($ci['low'] ?? null),
            $this->percent($ci['high'] ?? null),
            $this->percent($summary['group_recall']),
            $summary['mrr'],
            $summary['ndcg'],
            $summary['avg_rows'],
            $summary['avg_chars'],
        ));

        if ($report['comparison'] !== null && ! isset($report['comparison']['error'])) {
            $comparison = $report['comparison'];
            $this->line(sprintf(
                'vs baseline: %s -> %s, only baseline=%d, only current=%d, McNemar p=%s, differences: %s',
                $this->percent($comparison['baseline_full_coverage']),
                $this->percent($comparison['full_coverage']),
                count($comparison['only_before']),
                count($comparison['only_after']),
                $comparison['p_value'],
                $comparison['differences'] === [] ? 'none' : implode(', ', $comparison['differences']),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function markdown(array $report): string
    {
        $meta = $report['metadata'];
        $summary = $report['summary'];
        $ci = $summary['full_coverage_ci95'];
        $lines = [
            '# Retrieval gold evaluation',
            '',
            "Generated: {$meta['generated_at']}  ",
            'Run: **'.($report['validity']['valid'] ? 'VALID' : 'INVALID').'**'
                .($report['validity']['infrastructure_failures'] === [] ? '' : ' ('.json_encode($report['validity']['infrastructure_failures']).')'),
            '',
            '| Setting | Value |',
            '|---|---|',
            '| Dataset | '.$meta['dataset']['file'].' v'.($meta['dataset']['version'] ?? '?').' ('.substr((string) $meta['dataset']['sha1'], 0, 10).') |',
            "| Split / mode / stage | {$meta['split']} / {$meta['mode']} / {$meta['stage']} |",
            "| top_k (strict) | {$meta['top_k']} |",
            '| Commit | '.substr((string) ($meta['git']['commit'] ?? '?'), 0, 10).' ('.($meta['git']['dirty_files'] ?? '?').' modified files) |',
            '| Knowledge base | '.($meta['knowledge_base']['chunks'] ?? '?').' chunks, '.($meta['knowledge_base']['fingerprint'] ?? '?').' |',
            "| Embedding model | {$meta['embedding_model']} |",
            '',
            '| Metric | Value |',
            '|---|---:|',
            "| Cases | {$summary['cases']} |",
            '| Full evidence coverage | '.$this->percent($summary['full_coverage']).' (95% CI '.$this->percent($ci['low'] ?? null).'-'.$this->percent($ci['high'] ?? null).') |',
            '| Group recall | '.$this->percent($summary['group_recall']).' |',
            '| Hit rate | '.$this->percent($summary['hit_rate']).' |',
            "| MRR | {$summary['mrr']} |",
            "| nDCG | {$summary['ndcg']} |",
            '| Evidence precision (lower bound) | '.$this->percent($summary['evidence_precision']).' |',
            "| Avg chunks / chars | {$summary['avg_rows']} / {$summary['avg_chars']} |",
            '',
            '## By question type',
            '',
            '| Type | Cases | Full coverage | Group recall | MRR |',
            '|---|---:|---:|---:|---:|',
        ];

        foreach ($report['by_type'] as $type => $typeSummary) {
            $lines[] = "| {$type} | {$typeSummary['cases']} | ".$this->percent($typeSummary['full_coverage']).' | '
                .$this->percent($typeSummary['group_recall'])." | {$typeSummary['mrr']} |";
        }

        $misses = array_filter($report['rows'], static fn (array $row): bool => ! $row['full']);
        $lines[] = '';
        $lines[] = '## Incomplete cases';
        $lines[] = '';

        foreach ($misses as $row) {
            $lines[] = "- {$row['id']} ({$row['type']}): groups {$row['groups_satisfied']}/{$row['groups_total']}, missing ".json_encode($row['missing_groups']);
        }

        if ($misses === []) {
            $lines[] = 'None.';
        }

        if (($comparison = $report['comparison']) !== null && ! isset($comparison['error'])) {
            $lines[] = '';
            $lines[] = '## Comparison with baseline';
            $lines[] = '';
            $lines[] = "Baseline: {$comparison['baseline']}".($comparison['baseline_valid'] ? '' : ' (**baseline run was invalid**)');
            $lines[] = '';
            $lines[] = 'Full coverage '.$this->percent($comparison['baseline_full_coverage']).' -> '.$this->percent($comparison['full_coverage'])
                ." on {$comparison['paired']} paired cases. Only baseline: ".json_encode($comparison['only_before'])
                .'; only current: '.json_encode($comparison['only_after'])."; exact McNemar p = {$comparison['p_value']}.";
            $lines[] = '';
            $lines[] = 'Settings that differ: '.($comparison['differences'] === [] ? 'none' : implode(', ', $comparison['differences'])).'.';
        }

        return implode("\n", $lines)."\n";
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'N/A' : round($value * 100, 1).'%';
    }
}
