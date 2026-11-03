<?php

namespace App\Console\Commands;

use App\Services\Evaluation\ConversationReplayEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class ConversationReplay extends Command
{
    protected $signature = 'assistant:conversation-replay
        {--scenario=all : all, integration, presentation, boundaries, regression8, regression9, regression11, regression13, regression15, phrasing15, regression17, phrasing17, regression19, combinations19, regression21, classification21 or callback_followups}
        {--base-url=http://127.0.0.1:8765 : Local test server}
        {--output=storage/app/evaluations/conversation-replay.json : JSON transcript and results}
        {--limit=0 : Stop after this many requests, allowing a server restart}
        {--resume : Resume a saved campaign without repeating completed requests}
        {--throttle-ms=2200 : Delay between requests to respect the API rate limit}';

    protected $description = 'Replay multi-turn conversation regressions through the real local HTTP API; never calls the ticket endpoint.';

    public function handle(ConversationReplayEvaluator $evaluator): int
    {
        $base = rtrim((string) $this->option('base-url'), '/');
        if (! in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('Use a local test server URL.');

            return self::FAILURE;
        }
        $scenarios = require resource_path('evaluation/conversations.php');
        $selected = (string) $this->option('scenario');
        if ($selected !== 'all') {
            $scenarios = array_filter($scenarios, static fn ($key) => $key === $selected, ARRAY_FILTER_USE_KEY);
        }
        if ($scenarios === []) {
            $this->error('Unknown scenario.');

            return self::FAILURE;
        }
        $path = (string) $this->option('output');
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
            || strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && in_array($path[2], ['/', '\\'], true);
        if (! $absolute) {
            $path = base_path($path);
        }
        $run = ['dataset_hash' => hash_file('sha256', resource_path('evaluation/conversations.php')), 'scenario' => $selected, 'base_url' => $base, 'started_at' => now()->toIso8601String(), 'rows' => []];
        if ($this->option('resume')) {
            $saved = is_file($path) ? json_decode(file_get_contents($path), true) : null;
            if (! is_array($saved) || $saved['dataset_hash'] !== $run['dataset_hash'] || $saved['scenario'] !== $selected || $saved['base_url'] !== $base) {
                $this->error('Saved campaign does not match the dataset, scenario or server.');

                return self::FAILURE;
            }
            $run = $saved;
        }
        $count = 0;
        $references = [];
        $completed = [];
        foreach ($run['rows'] as $row) {
            $completed[$row['scenario'].':'.$row['turn']] = true;
            if (! empty($row['response']['conversation_id'])) {
                $references[$row['scenario']] = $row['response']['conversation_id'];
            }
        }
        foreach ($scenarios as $name => $cases) {
            foreach ($cases as $index => $case) {
                $turn = $index + 1;
                if (isset($completed[$name.':'.$turn])) {
                    continue;
                }
                $payload = ['question' => $case['question']];
                if (isset($references[$name])) {
                    $payload['conversation_id'] = $references[$name];
                }
                $start = microtime(true);
                try {
                    $response = Http::timeout(150)->acceptJson()->post($base.'/api/mistral-raw/ask', $payload);
                    $http = $response->status();
                    $data = $response->json();
                    if (! is_array($data)) {
                        $data = ['error' => 'Invalid JSON HTTP response.'];
                    }
                } catch (Throwable $exception) {
                    $http = 0;
                    $data = ['error' => $exception->getMessage()];
                }
                $failures = $evaluator->evaluate($case, $http, $data);
                $next = $data['conversation_id'] ?? null;
                if ($next !== null && isset($references[$name]) && $next !== $references[$name]) {
                    $failures[] = 'conversation_changed';
                }
                if ($next !== null) {
                    $references[$name] = $next;
                }
                $run['rows'][] = ['scenario' => $name, 'turn' => $turn, 'question' => $case['question'], 'http' => $http, 'seconds' => round(microtime(true) - $start, 2), 'failures' => $failures, 'response' => $data];
                $run['updated_at'] = now()->toIso8601String();
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                file_put_contents($path, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line(($failures === [] ? 'PASS' : 'FAIL').' '.$name.' #'.$turn.($failures === [] ? '' : ' '.implode(', ', $failures)));
                $count++;
                if ((int) $this->option('limit') > 0 && $count >= (int) $this->option('limit')) {
                    break 2;
                }
                usleep(max(0, (int) $this->option('throttle-ms')) * 1000);
            }
        }
        $failed = count(array_filter($run['rows'], static fn ($row) => $row['failures'] !== []));
        $this->info(count($run['rows']).' messages saved; '.$failed.' failed. '.$path);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
