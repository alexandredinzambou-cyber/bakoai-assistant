<?php

namespace App\Console\Commands;

use App\Services\Rag\EmbeddingService;
use App\Services\Rag\EvalDatasetExpander;
use App\Services\Rag\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class EmbeddingEval extends Command
{
    protected $signature = 'embedding:eval
        {--file=database/seeders/eval_questions.json : Reference dataset}
        {--format=both : markdown, json or both}
        {--top-k=10 : Depth of the ranked document list to evaluate}
        {--skip-hybrid : Only evaluate the raw vector search, skip the hybrid RetrievalService comparison}
        {--skip-determinism : Skip the repeat-embedding stability probe}';

    protected $description = 'Measure embedding/retrieval quality (Recall@k, MRR, corpus coherence) independently of LLM answer generation.';

    public function handle(
        EmbeddingService $embeddingService,
        RetrievalService $retrievalService,
        EvalDatasetExpander $expander,
    ): int {
        $file = base_path((string) $this->option('file'));
        $format = (string) $this->option('format');
        $topK = max(1, (int) $this->option('top-k'));
        $skipHybrid = (bool) $this->option('skip-hybrid');

        if (! File::exists($file) || ! in_array($format, ['markdown', 'json', 'both'], true)) {
            $this->error(! File::exists($file) ? "Eval file not found: {$file}" : 'Format must be markdown, json or both.');

            return self::INVALID;
        }

        try {
            $payload = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid evaluation JSON: '.$exception->getMessage());

            return self::INVALID;
        }

        $items = array_values(array_filter(
            $expander->expand($payload),
            static fn (array $item): bool => ($item['category'] ?? null) === 'answerable'
                && ($item['expected_source_documents'] ?? []) !== [],
        ));

        if ($items === []) {
            $this->error('No answerable items with expected_source_documents found in the dataset.');

            return self::INVALID;
        }

        $candidateDepth = max($topK * 6, 60);
        $ranks = ['vector' => [], 'hybrid' => []];
        $rows = [];

        foreach ($items as $item) {
            $question = (string) $item['question'];
            $moyenPaiement = is_string($item['moyen_paiement'] ?? null) ? $item['moyen_paiement'] : null;
            $operation = is_string($item['operation'] ?? null) ? $item['operation'] : null;
            $expectedLower = array_map(
                static fn (string $document): string => Str::lower($document),
                $item['expected_source_documents'],
            );

            $vectorRanking = $this->documentRanking(
                $retrievalService->search($question, $moyenPaiement, $operation, $candidateDepth, vectorOnly: true),
                $topK,
            );
            $vectorRank = $this->rankOf($vectorRanking, $expectedLower);
            $ranks['vector'][] = $vectorRank;

            $hybridRank = null;

            if (! $skipHybrid) {
                $hybridRanking = $this->documentRanking(
                    $retrievalService->search($question, $moyenPaiement, $operation, $candidateDepth),
                    $topK,
                );
                $hybridRank = $this->rankOf($hybridRanking, $expectedLower);
                $ranks['hybrid'][] = $hybridRank;
            }

            $rows[] = [
                'id' => $item['id'] ?? null,
                'question' => $question,
                'expected_source_documents' => $item['expected_source_documents'],
                'vector_rank' => $vectorRank,
                'vector_top1' => $vectorRanking[0] ?? null,
                'hybrid_rank' => $hybridRank,
            ];
        }

        // RetrievalService resolves its own EmbeddingService instance, so this
        // injected instance has not necessarily embedded anything yet. Force one
        // call to make lastModel() reflect the actually configured provider
        // instead of falling back to the local-hash default.
        $embeddingService->embedForQuery((string) $items[0]['question']);
        $model = $embeddingService->lastModel();
        $metrics = ['vector' => $this->metrics($ranks['vector'], $topK)];

        if (! $skipHybrid) {
            $metrics['hybrid'] = $this->metrics($ranks['hybrid'], $topK);
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'dataset_note' => $payload['note'] ?? null,
            'embedding_model' => $model,
            'top_k' => $topK,
            'metrics' => $metrics,
            'dimension_integrity' => $this->dimensionIntegrity($model),
            'corpus_coherence' => $this->corpusCoherence($model),
            'determinism' => $this->option('skip-determinism')
                ? null
                : $this->determinism($embeddingService, (string) $items[0]['question']),
            'rows' => $rows,
        ];

        File::ensureDirectoryExists(storage_path('app/eval-reports'));
        $baseTarget = storage_path('app/eval-reports/embedding-eval-'.now()->format('Ymd-His'));
        $targets = [];

        if (in_array($format, ['json', 'both'], true)) {
            $targets[] = $target = $baseTarget.'.json';
            File::put($target, json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        }

        if (in_array($format, ['markdown', 'both'], true)) {
            $targets[] = $target = $baseTarget.'.md';
            File::put($target, $this->markdown($report));
        }

        foreach ($targets as $target) {
            $this->info("Report written to {$target}");
        }

        $this->line("Embedding model: {$model}");
        $this->line('Vector-only  '.$this->summarizeMetrics($metrics['vector']));

        if (isset($metrics['hybrid'])) {
            $this->line('Hybrid       '.$this->summarizeMetrics($metrics['hybrid']));
        }

        if ($report['corpus_coherence']['separation'] !== null) {
            $this->line('Corpus coherence (same-doc vs different-doc avg similarity): '.$report['corpus_coherence']['separation']);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string> lower-cased document titles, best rank first
     */
    private function documentRanking(Collection $rows, int $limit): array
    {
        $seen = [];
        $ranking = [];

        foreach ($rows as $row) {
            $title = Str::lower((string) $row->document_titre);

            if (isset($seen[$title])) {
                continue;
            }

            $seen[$title] = true;
            $ranking[] = $title;

            if (count($ranking) >= $limit) {
                break;
            }
        }

        return $ranking;
    }

    /**
     * @param  list<string>  $ranking
     * @param  list<string>  $expectedLower
     */
    private function rankOf(array $ranking, array $expectedLower): ?int
    {
        foreach ($ranking as $index => $title) {
            if (in_array($title, $expectedLower, true)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  list<int|null>  $ranks
     */
    private function metrics(array $ranks, int $topK): array
    {
        $total = count($ranks);
        $kValues = array_values(array_unique(array_filter(
            [1, 3, 5, $topK],
            static fn (int $k): bool => $k <= $topK,
        )));
        sort($kValues);

        $recall = [];

        foreach ($kValues as $k) {
            $hits = count(array_filter($ranks, static fn (?int $rank): bool => $rank !== null && $rank <= $k));
            $recall["recall_at_{$k}"] = $total > 0 ? round($hits / $total, 4) : null;
        }

        $mrr = $total > 0
            ? round(array_sum(array_map(
                static fn (?int $rank): float => $rank !== null ? 1 / $rank : 0.0,
                $ranks,
            )) / $total, 4)
            : null;

        return [
            'questions' => $total,
            ...$recall,
            'mrr' => $mrr,
            'not_found_in_top_k' => count(array_filter($ranks, static fn (?int $rank): bool => $rank === null)),
        ];
    }

    private function summarizeMetrics(array $metrics): string
    {
        $parts = [];

        foreach ($metrics as $key => $value) {
            if (! str_starts_with($key, 'recall_at_') && $key !== 'mrr') {
                continue;
            }

            $parts[] = $key.'='.($value === null ? 'n/a' : $value);
        }

        return implode(' ', $parts)." (not found in top-k: {$metrics['not_found_in_top_k']}/{$metrics['questions']})";
    }

    private function dimensionIntegrity(string $model): array
    {
        $expected = (int) config('rag.embedding_dimensions', 768);
        $total = (int) DB::table('embeddings')->where('modele', $model)->count();
        $mismatched = (int) DB::table('embeddings')
            ->where('modele', $model)
            ->whereRaw('vector_dims(vecteur) <> ?', [$expected])
            ->count();

        return [
            'expected_dimensions' => $expected,
            'total_vectors' => $total,
            'dimension_mismatches' => $mismatched,
        ];
    }

    private function corpusCoherence(string $model): array
    {
        $same = DB::selectOne(
            'SELECT AVG(1 - (e1.vecteur <=> e2.vecteur)) AS avg_similarity, COUNT(*) AS pairs
             FROM embeddings e1
             JOIN embeddings e2 ON e1.chunk_id < e2.chunk_id AND e1.modele = e2.modele
             JOIN chunks c1 ON c1.id = e1.chunk_id
             JOIN chunks c2 ON c2.id = e2.chunk_id
             WHERE e1.modele = ? AND c1.document_id = c2.document_id',
            [$model],
        );

        $different = DB::selectOne(
            'SELECT AVG(1 - (e1.vecteur <=> e2.vecteur)) AS avg_similarity, COUNT(*) AS pairs
             FROM embeddings e1
             JOIN embeddings e2 ON e1.chunk_id < e2.chunk_id AND e1.modele = e2.modele
             JOIN chunks c1 ON c1.id = e1.chunk_id
             JOIN chunks c2 ON c2.id = e2.chunk_id
             WHERE e1.modele = ? AND c1.document_id <> c2.document_id',
            [$model],
        );

        $sameAvg = $same !== null && (int) $same->pairs > 0 ? round((float) $same->avg_similarity, 4) : null;
        $differentAvg = $different !== null && (int) $different->pairs > 0 ? round((float) $different->avg_similarity, 4) : null;

        return [
            'same_document_avg_similarity' => $sameAvg,
            'same_document_pairs' => $same !== null ? (int) $same->pairs : 0,
            'different_document_avg_similarity' => $differentAvg,
            'different_document_pairs' => $different !== null ? (int) $different->pairs : 0,
            'separation' => $sameAvg !== null && $differentAvg !== null ? round($sameAvg - $differentAvg, 4) : null,
        ];
    }

    private function determinism(EmbeddingService $embeddingService, string $probe): ?float
    {
        try {
            $first = $embeddingService->embedForQuery($probe);
            $second = $embeddingService->embedForQuery($probe);
        } catch (Throwable) {
            return null;
        }

        return round($this->cosine($first, $second), 6);
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = $b[$index] ?? 0.0;
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function markdown(array $report): string
    {
        $lines = [
            '# Embedding evaluation report',
            '',
            "Generated: {$report['generated_at']}",
            '',
            "Embedding model: **{$report['embedding_model']}**",
            '',
            '| Metric | Vector-only | Hybrid |',
            '|---|---:|---:|',
        ];

        $vector = $report['metrics']['vector'];
        $hybrid = $report['metrics']['hybrid'] ?? null;

        foreach ($vector as $key => $value) {
            if ($key === 'questions') {
                continue;
            }

            $vectorValue = $value ?? 'n/a';
            $hybridValue = $hybrid[$key] ?? null;
            $hybridValue = $hybrid === null ? 'n/a' : ($hybridValue ?? 'n/a');
            $lines[] = "| {$key} | {$vectorValue} | {$hybridValue} |";
        }

        $lines[] = '';
        $lines[] = "Questions evaluated: {$vector['questions']}";
        $lines[] = '';
        $lines[] = '## Dimension integrity';
        $lines[] = '';
        $lines[] = "Expected dimensions: {$report['dimension_integrity']['expected_dimensions']}, "
            ."total vectors: {$report['dimension_integrity']['total_vectors']}, "
            ."mismatches: {$report['dimension_integrity']['dimension_mismatches']}";
        $lines[] = '';
        $lines[] = '## Corpus coherence';
        $lines[] = '';
        $coherence = $report['corpus_coherence'];
        $lines[] = 'Average cosine similarity between chunks of the same document: '
            .($coherence['same_document_avg_similarity'] ?? 'n/a')." ({$coherence['same_document_pairs']} pairs)";
        $lines[] = 'Average cosine similarity between chunks of different documents: '
            .($coherence['different_document_avg_similarity'] ?? 'n/a')." ({$coherence['different_document_pairs']} pairs)";
        $lines[] = 'Separation (higher is healthier): '.($coherence['separation'] ?? 'n/a');

        if ($report['determinism'] !== null) {
            $lines[] = '';
            $lines[] = '## Determinism';
            $lines[] = '';
            $lines[] = 'Cosine similarity between two embeddings of the same probe text: '.$report['determinism'];
        }

        $lines[] = '';
        $lines[] = '## Questions not found within top-k (vector-only)';
        $lines[] = '';

        foreach ($report['rows'] as $row) {
            if ($row['vector_rank'] !== null) {
                continue;
            }

            $lines[] = '- '.$row['id'].': "'.$row['question'].'" expected '
                .implode(', ', $row['expected_source_documents'])
                .' but top match was "'.($row['vector_top1'] ?? 'n/a').'"';
        }

        return implode("\n", $lines)."\n";
    }
}
