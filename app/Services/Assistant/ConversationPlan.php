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
        $answer = preg_replace('/```.*?```|~~~.*?~~~/su', '', $answer) ?? '';
        $answer = preg_replace('/^\*(?:Certains points|Les valeurs d.exemple).*$/mu', '', $answer) ?? $answer;
        $answer = preg_replace('/^Exemple documentaire[^\r\n]*$/mu', '', $answer) ?? $answer;
        $answer = preg_replace('/[^\r\n]*\b(?:exemple|payload)\b[^\r\n]*\bci-dessous\b[^\r\n]*:?\s*$/imu', '', $answer) ?? $answer;
        preg_match_all('/^\d+[.)]\s+[^\r\n]+(?:\R(?!\d+[.)]\s)[^\r\n]*)*/mu', $answer, $matches);
        $steps = array_values(array_filter(array_map(static fn ($step): string => trim(preg_replace('/^\d+[.)]\s+/u', '', $step) ?? ''), $matches[0])));

        return count($steps) >= 2 ? array_slice($steps, 0, 15) : [];
    }
}
