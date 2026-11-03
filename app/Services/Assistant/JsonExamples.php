<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/** Keep JSON roles and keys separate; never merge a notification into its acknowledgement. */
class JsonExamples
{
    public function extract(string $answer): array
    {
        preg_match_all('/```json\s*(.*?)```/su', $answer, $blocks, PREG_OFFSET_CAPTURE);
        $examples = [];
        $previousEnd = 0;
        foreach ($blocks[1] as $index => $block) {
            $decoded = json_decode($block[0], true);
            if (! is_array($decoded) || array_is_list($decoded)) {
                continue;
            }
            $prefix = substr($answer, $previousEnd, $blocks[0][$index][1] - $previousEnd);
            $previousEnd = $blocks[0][$index][1] + strlen($blocks[0][$index][0]);
            // A chunk may contain several sections: only the closest heading describes this object.
            preg_match_all('/^#{1,6}\s+[^\r\n]+/mu', $prefix, $headings, PREG_OFFSET_CAPTURE);
            if ($headings[0] !== []) {
                $heading = end($headings[0]);
                $prefix = substr($prefix, $heading[1]);
            }
            $flat = Str::ascii(mb_strtolower($prefix));
            $role = match (true) {
                isset($decoded['responseCode'],$decoded['transactionId']) => 'ack',
                isset($decoded['transactionId'],$decoded['code'],$decoded['status']) => 'incoming',
                preg_match('/accuse|a renvoyer|repondre|reponse.*serveur/u', $flat) === 1 => 'ack',
                preg_match('/notification|recu|entrant/u', $flat) === 1 => 'incoming',
                preg_match('/reponse|resultat|sortant/u', $flat) === 1 => 'response',
                preg_match('/requete|a envoyer|corps|payload/u', $flat) === 1 => 'request',
                default => 'unknown',
            };
            $fingerprint = hash('sha256', json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $entry = ['role' => $role, 'keys' => array_keys($decoded), 'block' => $index, 'fingerprint' => $fingerprint];
            $examples[$role.':'.$fingerprint] = $entry;
        }

        return array_values($examples);
    }

    public function select(array $examples, string $question): array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $contrasts = preg_split('/\bmais\b/u', $flat) ?: [$flat];
        $flat = end($contrasts);
        $clauses = preg_split('/[.!?;]+/u', $flat) ?: [$flat];
        $clauses = array_map(static function ($clause) {
            $negativePrefix = preg_split('/,|\b(?:donne|montre|liste|affiche|uniquement|seulement)\b/u', $clause)[0];
            if (preg_match('/\b(?:je ne (?:veux|souhaite) pas|pas (?:de|le|la|l)|sans)\b/u', $negativePrefix) === 1
                && preg_match('/accuse|notification|requete|reponse/u', $negativePrefix) === 1) {
                return preg_match('/\b(?:donne|montre|liste|affiche)\b.*$/u', $clause, $positive) === 1 ? $positive[0] : '';
            }

            return $clause;
        }, $clauses);
        $flat = implode(' ', $clauses);
        $flat = preg_split('/,\s*(?:pas|ni)\b/u', $flat)[0];
        $role = match (true) {
            preg_match('/renvoyer|accuse|repondre/u', $flat) === 1 => 'ack',
            preg_match('/notification|entrant|recu/u', $flat) === 1 => 'incoming',
            preg_match('/requete|envoyer/u', $flat) === 1 => 'request',
            preg_match('/reponse|sortant/u', $flat) === 1 => 'response',
            default => null,
        };
        if ($role !== null) {
            $selected = array_values(array_filter($examples, static function ($example) use ($role) {
                $keys = $example['keys'];
                $storedRole = match (true) {
                    in_array('transactionId', $keys, true) && in_array('responseCode', $keys, true) => 'ack',
                    in_array('transactionId', $keys, true) && in_array('code', $keys, true) && in_array('status', $keys, true) => 'incoming',
                    default => $example['role'],
                };

                return $storedRole === $role || $role === 'response' && $storedRole === 'ack';
            }));
            $examples = $selected;
        }

        return $examples;
    }

    public function fields(array $examples, string $question): string
    {
        $examples = $this->select($examples, $question);
        if (count($examples) > 1) {
            $schemas = array_map(static function ($e) {
                $keys = $e['keys'];
                sort($keys);

                return json_encode($keys);
            }, $examples);
            if (count(array_unique($schemas)) === 1) {
                $examples = [$examples[0]];
            }
        }
        if (count($examples) === 1) {
            return implode(', ', array_map(static fn ($key) => '`'.$key.'`', $examples[0]['keys'])).'.';
        }

        return $examples === [] ? 'Je ne dispose pas d’un objet JSON vérifié pour ce rôle. Précisez la requête ou la réponse concernée.'
            : 'Plusieurs objets JSON sont disponibles. Précisez si vous souhaitez les champs de la requête, de la notification ou de la réponse.';
    }

    public function status(string $answer, string $question): string
    {
        preg_match_all('/```json\s*(.*?)```/su', $answer, $blocks);
        $statuses = [];
        foreach ($this->select($this->extract($answer), $question) as $example) {
            $decoded = json_decode($blocks[1][$example['block']] ?? '', true);
            if (is_array($decoded) && isset($decoded['status']) && is_string($decoded['status'])) {
                $statuses[] = $decoded['status'];
            }
        }
        $statuses = array_values(array_unique($statuses));

        return count($statuses) === 1 ? 'Le champ `status` de l’exemple vérifié vaut `'.$statuses[0].'`.'
            : 'Je ne dispose pas d’un statut unique dans les exemples JSON vérifiés. Précisez l’exemple concerné.';
    }

    public function example(string $answer, string $question): string
    {
        $examples = $this->select($this->extract($answer), $question);
        preg_match_all('/```json\s*(.*?)```/su', $answer, $blocks);
        $contrasts = preg_split('/\bmais\b/iu', $question) ?: [$question];
        $constraintQuestion = preg_split('/,\s*(?:pas|ni)\b/iu', end($contrasts))[0];
        $negativePattern = '/\b(?:je ne (?:veux|souhaite) pas|pas|sans|sauf|ni|[eé]vite[rz]?|exclu(?:s|re|ez)|ignore[rz]?)\s+(?:avec\s+)?(?:(?:de|le|du)\s+)?(?:statut|status)\s*["\'`*]*([A-Z][A-Z0-9_-]*)["\'`*]*/iu';
        preg_match_all($negativePattern, $question, $negativeStatuses);
        $excluded = array_map('strtoupper', $negativeStatuses[1]);
        $examples = array_values(array_filter($examples, static function ($example) use ($blocks, $excluded) {
            $decoded = json_decode($blocks[1][$example['block']] ?? '', true);

            return ! in_array(strtoupper((string) ($decoded['status'] ?? '')), $excluded, true);
        }));
        $constraintQuestion = preg_replace($negativePattern, '', $constraintQuestion) ?? $constraintQuestion;
        if (preg_match('/\b(?:statut|status)\s*(?:est|vaut|=|:)?\s*["\'`*]*([A-Z][A-Z0-9_-]*)\b/iu', $constraintQuestion, $requested) === 1
            && ! in_array(mb_strtolower($requested[1]), ['de', 'du', 'des', 'dans', 'pour', 'contient'], true)) {
            $status = strtoupper($requested[1]);
            $examples = array_values(array_filter($examples, static function ($example) use ($blocks, $status) {
                $decoded = json_decode($blocks[1][$example['block']] ?? '', true);

                return is_array($decoded) && strtoupper((string) ($decoded['status'] ?? '')) === $status;
            }));
            if ($examples === []) {
                return 'Aucun exemple JSON vérifié ne correspond au statut '.$status.'. Je ne peux pas modifier les valeurs documentaires pour le fabriquer.';
            }
        }
        if ($examples === [] && $excluded !== []) {
            return 'Aucun exemple JSON vérifié ne respecte les exclusions de statut demandées. Je ne peux pas modifier les valeurs documentaires pour le fabriquer.';
        }
        if (count($examples) !== 1) {
            return 'Précisez quel exemple JSON vérifié vous souhaitez reprendre : requête, notification ou réponse.';
        }
        $json = $blocks[1][$examples[0]['block']] ?? null;
        if ($json === null) {
            return 'L’exemple JSON complet n’est plus disponible. Reposez la question documentaire complète.';
        }

        $notice = 'Exemple documentaire repris de la réponse précédente ; les valeurs affichées sont des exemples.';
        if ($examples[0]['role'] === 'ack') {
            $notice .= ' Reprenez dynamiquement les valeurs du message reçu.';
        }

        return $notice."\n\n```json\n".trim($json)."\n```";
    }
}
