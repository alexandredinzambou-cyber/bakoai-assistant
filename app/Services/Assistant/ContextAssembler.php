<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;

/**
 * Chooses which retrieved chunks are sent to the model. Handing it every top_k row (plus the
 * gap-bridged ones) made it restate most of them: the model uses whatever it receives, and every
 * extra restated passage is one more chance to fail the citation check. A chunk is kept when it
 * scores within a fraction of the best one and fits the character budget; the first few are
 * always kept. Tuned on the chunk-level gold set: at ratio 0.6 / 10 000 chars the context shrinks
 * by about 30 % with no loss of the passages the answers need.
 */
final class ContextAssembler
{
    /**
     * @param  Collection<int, object>  $chunks  ranked retrieval rows (ranking_score when present)
     * @return Collection<int, object>
     */
    public function select(Collection $chunks, string $question = ''): Collection
    {
        $chunks = (new WebhookScope)->select($chunks, $question);
        if ($question !== '') {
            $chunks = $chunks->filter(static function ($chunk) use ($question): bool {
                $title = (string) data_get($chunk, 'document_titre', '').' '.data_get($chunk, 'section', '');
                foreach (['prestashop', 'woocommerce', 'wordpress'] as $platform) {
                    if (stripos($title, $platform) !== false && stripos($question, $platform) === false) {
                        return false;
                    }
                }

                return true;
            });
        }

        $ratio = (float) config('rag.context_relative_score', 0.6);
        $budget = (int) config('rag.context_budget_chars', 10000);
        $minimum = max(1, (int) config('rag.context_min_chunks', 3));
        $chunks = $chunks->values();
        $top = (float) data_get($chunks->first(), 'ranking_score', data_get($chunks->first(), 'relevance_score', 0.0));
        $kept = collect();
        $chars = 0;

        foreach ($chunks as $index => $chunk) {
            $score = (float) data_get($chunk, 'ranking_score', data_get($chunk, 'relevance_score', 0.0));
            $length = mb_strlen((string) data_get($chunk, 'contenu', ''));

            if ($index < $minimum || ($score >= $top * $ratio && $chars + $length <= $budget)) {
                $kept->push($chunk);
                $chars += $length;
            }
        }

        return $kept->values();
    }
}
