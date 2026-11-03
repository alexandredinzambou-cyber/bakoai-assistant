<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class ConversationPlan
{
    public function procedureSubject(string $question): ?string
    {
        return $this->subject($question);
    }

    public function subject(string $question): ?string
    {
        $flat = Str::ascii(mb_strtolower($question));
        $flat = preg_split('/[,;]\s*(?:pas|ni)\b/u', $flat)[0];
        $flat = preg_replace('/\b(?:pas|ni|sans)\s+(?:le|la|les|un|une|de|du|des|l)\b[^,;]*/u', '', $flat) ?? $flat;
        if (preg_match('/\breporting\b|\b(?:webhook|notification)\b.{0,40}\bexports?\b/u', $flat) === 1
            && preg_match('/\b(?:duree|delai|validite|conserv\w*)\b/u', $flat) !== 1) {
            return 'reporting';
        }
        if (preg_match('/\b(?:plan|parcours|etapes?|procedure|passage|prerequis|test\w*|integr\w*)\b/u', $flat) === 1) {
            if (preg_match('/\bproduction\b/u', $flat) === 1) {
                return 'production';
            }
            if (preg_match('/\bsandbox\b/u', $flat) === 1) {
                return 'sandbox';
            }
        }
        if (preg_match('/\brembours\w*\b/u', $flat) === 1 && ! preg_match('/\b(?:callbacks?|webhooks?|frais|cle|secret|solde|kyc|statut)\b/u', $flat)) {
            return 'refund';
        }
        if (preg_match('/\b(?:callbacks?|webhooks?)\b|\bnotifications?\b.{0,30}\bpaiement\w*\b/u', $flat) === 1) {
            return 'callback';
        }
        foreach (['callback' => '/\b(?:callback|webhook)\b/', 'export' => '/\b(?:exports?|reporting|rapport)\b/', 'fees' => '/\bfrais\b/', 'secret' => '/\b(?:cle|secret)\b/', 'refund' => '/\brembours\w*\b/', 'balance' => '/\bsolde\b/', 'kyc' => '/\bkyc\b/', 'operators' => '/\boperateurs\b/', 'status' => '/\b(?:check status|statut)\b/', 'payment' => '/\bpaiement\w*\b/'] as $name => $pattern) {
            if ($name === 'status' && ((new ConversationActions)->protocolExampleRequest($question) || (new ConversationActions)->jsonStatusRequest($question)) && ! str_contains($flat, 'check status')) {
                continue;
            }
            if (preg_match($pattern, $flat) === 1) {
                return $name;
            }
        }

        if (preg_match('/\bsandbox\b/u', $flat) === 1) {
            return 'sandbox';
        }
        if (preg_match('/\bproduction\b/u', $flat) === 1) {
            return 'production';
        }

        return null;
    }

    /** @return list<string> */
    public function extract(string $answer): array
    {
        // Hide examples while locating list boundaries; restore their exact bytes afterwards.
        $blocks = [];
        $prefix = '@@PLAN_CODE_';
        while (str_contains($answer, $prefix)) {
            $prefix = '_'.$prefix;
        }
        $answer = preg_replace_callback('/^[ \t]*(`{3,}|~{3,})[^\r\n]*\R[\s\S]*?^[ \t]*\1[ \t]*$/mu', static function ($match) use (&$blocks, $prefix) {
            $key = $prefix.count($blocks).'@@';
            $blocks[$key] = $match[0];

            return $key;
        }, $answer) ?? $answer;
        $answer = preg_replace('/^\*(?:Certains points|Les valeurs d.exemple).*$/mu', '', $answer) ?? $answer;
        preg_match_all('/^\d+[.)]\s+[^\r\n]+(?:\R(?!\d+[.)]\s)[^\r\n]*)*/mu', $answer, $matches);
        $steps = array_values(array_filter(array_map(fn ($step): string => $this->withoutEmptyExample(trim(strtr(preg_replace('/^\d+[.)]\s+/u', '', $step) ?? '', $blocks))), $matches[0])));

        return count($steps) >= 2 ? array_slice($steps, 0, 15) : [];
    }

    /** Recover legacy examples only when the full plan and source numbering still match. */
    public function repairSteps(array $memory): array
    {
        $steps = $memory['plan_steps'] ?? $this->extract($memory['last_answer'] ?? '');
        $candidates = [$memory['plan_answer'] ?? ''];
        if (($memory['payload_sources'] ?? []) === ($memory['plan_sources'] ?? [])) {
            $candidates[] = $memory['payload_answer'] ?? '';
        }
        $titles = static fn (array $items) => array_map(static fn ($item) => trim(Str::ascii(mb_strtolower(preg_replace('/\[\d+\]/u', '', preg_split('/\R/u', $item)[0])))), $items);
        foreach ($candidates as $candidate) {
            $recovered = $this->extract($candidate);
            if ($recovered !== [] && count($recovered) === count($steps) && $titles($recovered) === $titles($steps)) {
                return $recovered;
            }
        }

        return array_map(fn ($step) => $this->withoutEmptyExample($step), $steps);
    }

    public function withoutEmptyExample(string $step): string
    {
        $lines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/u', $step) ?: [];
        $fence = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/u', $line, $marker) === 1) {
                $fence = $fence === null ? $marker[1] : ($marker[1] === $fence ? null : $fence);

                continue;
            }
            if ($fence !== null || preg_match('/^\s*(?:[-*]\s+)?\*{0,2}Exemple[^\r\n]*:\s*\*{0,2}\s*$/iu', $line) !== 1) {
                continue;
            }
            $next = $index + 1;
            while (isset($lines[$next]) && trim($lines[$next]) === '') {
                $next++;
            }
            if (preg_match('/^\s*(?:`{3,}|~{3,})/u', $lines[$next] ?? '') !== 1) {
                unset($lines[$index]);
            }
        }

        return trim(implode('', $lines));
    }
}
