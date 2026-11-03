<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Keep the reporting and transaction notification contracts separate. */
final class WebhookScope
{
    public function select(Collection $chunks, string $question): Collection
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (preg_match('/\b(?:compar\w*|difference\w*|distingu\w*)\b/u', $flat) === 1) {
            return $chunks;
        }
        $subject = (new ConversationPlan)->subject($question);
        if (! in_array($subject, ['callback', 'reporting'], true)) {
            return $chunks;
        }

        return $chunks->reject(static function ($chunk) use ($subject): bool {
            $url = (string) data_get($chunk, 'lien_officiel', '');
            $title = Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', '')));
            $reporting = str_contains($url, '/webhooks/reports') || preg_match('/webhook.*reporting/u', $title) === 1;
            $status = str_contains($url, '/webhooks/status') || preg_match('/webhook.*statut/u', $title) === 1;

            return $subject === 'callback' ? $reporting : $status;
        })->values();
    }
}
