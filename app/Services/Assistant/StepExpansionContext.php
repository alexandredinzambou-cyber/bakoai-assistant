<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** A selected step may use its cited sections, without importing the entire guide. */
final class StepExpansionContext
{
    public function select(Collection $chunks, array $memory): Collection
    {
        $step = $memory['plan_steps'][$memory['plan_index'] ?? 0] ?? '';
        preg_match_all('/\[(\d+)\]/u', $step, $matches);
        $sources = [];
        foreach ($memory['plan_sources'] ?? [] as $index => $source) {
            if (in_array((string) ($source['number'] ?? $index + 1), $matches[1], true)) {
                $sources[] = $source;
            }
        }
        if ($sources === []) {
            return $chunks;
        }
        $receipt = ($memory['plan_subject'] ?? null) === 'callback'
            && preg_match('/recev\w*|recept\w*|notification/u', Str::ascii(mb_strtolower($step))) === 1;

        return $chunks->filter(static function ($chunk) use ($sources, $receipt): bool {
            $url = (string) data_get($chunk, 'lien_officiel', '');
            if ($receipt && str_contains($url, '/webhooks/status')) {
                return true;
            }
            foreach ($sources as $source) {
                if ($url !== ($source['url'] ?? null)) {
                    continue;
                }
                $root = trim(explode(' > ', (string) ($source['section'] ?? ''))[0]);
                $section = (string) data_get($chunk, 'section', '');
                if ($root === '' || $section === $root || str_starts_with($section, $root.' > ')) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
