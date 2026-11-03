<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DocumentedFactAnswer
{
    private function notificationFamily(string $question): ?string
    {
        $flat = Str::ascii(mb_strtolower($question));
        $flat = preg_split('/[,;]\s*(?:pas|ni)\b/u', $flat)[0];
        if (preg_match('/\breporting\b|\b(?:webhook|notification)\b.{0,40}\bexports?\b/u', $flat) === 1) {
            return 'reporting';
        }

        return preg_match('/\b(?:paiements?|transactions?)\b/u', $flat) === 1 ? 'transaction' : null;
    }

    public function reportingAcknowledgement(string $question, string $resolved, Collection $chunks): ?array
    {
        $family = $this->notificationFamily($question) ?? $this->notificationFamily($resolved);
        $flat = Str::ascii(mb_strtolower($question));
        if ($family !== 'reporting' || preg_match('/accus\w*|renvoyer|repondre/u', $flat) !== 1) {
            return null;
        }
        $matches = [];
        foreach ($chunks as $chunk) {
            $label = Str::ascii(mb_strtolower(data_get($chunk, 'document_titre', '').' '.data_get($chunk, 'section', '')));
            $text = (string) data_get($chunk, 'contenu', '');
            if (! str_contains($label, 'reporting') || preg_match('/r[eé]pondez\s+\*{0,2}2xx\b/iu', $text) !== 1
                || preg_match('/```json\s*(.*?)```/su', $text, $block) !== 1) {
                continue;
            }
            $decoded = json_decode($block[1], true);
            if ($decoded !== ['received' => true]) {
                continue;
            }
            $matches[json_encode($decoded)] = ['answer' => 'Pour le reporting, répondez 2xx dès que la notification est enregistrée, sans attendre le téléchargement du fichier. L’exemple documentaire utilise le champ `received` [SOURCE:'.$chunk->chunk_id."].\n\n```json\n".trim($block[1])."\n```", 'chunk' => $chunk];
        }

        return count($matches) === 1 ? reset($matches) : null;
    }

    public function jsonRoleFields(string $question, string $resolved, Collection $chunks, array $contextExamples = []): ?array
    {
        if (! (new ConversationActions)->fieldNamesRequest($question)) {
            return null;
        }
        $context = Str::ascii(mb_strtolower($resolved));
        if (preg_match('/callback|notification|accuse/u', $context) !== 1) {
            return null;
        }
        $json = new JsonExamples;
        $family = $this->notificationFamily($question) ?? $this->notificationFamily($resolved);
        if ($family === null && collect($contextExamples)->contains(static fn ($example) => in_array('transactionId', $example['keys'] ?? [], true))) {
            $family = 'transaction';
        }
        $matches = [];
        foreach ($chunks as $chunk) {
            $label = Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', '').' '.data_get($chunk, 'section', '')));
            if (preg_match('/callback|webhook|notification/u', $label) !== 1) {
                continue;
            }
            $examples = $json->extract((string) data_get($chunk, 'contenu', ''));
            if ($family === 'transaction') {
                // Reporting notifications belong to another contract despite the shared word "webhook".
                $examples = array_values(array_filter($examples, static fn ($example) => in_array('transactionId', $example['keys'], true)));
            } elseif ($family === 'reporting') {
                $examples = array_values(array_filter($examples, static fn ($example) => in_array('job_id', $example['keys'], true)
                    || in_array('event', $example['keys'], true) || in_array('received', $example['keys'], true)));
            }
            $answer = $json->fields($examples, $question);
            if (str_starts_with($answer, '`')) {
                $matches[$answer] = ['answer' => $answer.' [SOURCE:'.$chunk->chunk_id.'].', 'chunk' => $chunk, 'json_examples' => $examples, 'json_answer' => $chunk->contenu];
            }
        }

        return count($matches) === 1 ? reset($matches) : null;
    }

    public function statusCapability(string $question, string $resolved, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $context = Str::ascii(mb_strtolower($resolved));
        if (preg_match('/check status|api de statut/u', $context) !== 1 || preg_match('/annul\w*|rembours\w*|supprim\w*|modifi\w*|cette capacite/u', $flat) !== 1) {
            return null;
        }
        foreach ($chunks as $chunk) {
            $title = Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', '')));
            $text = (string) data_get($chunk, 'contenu', '');
            if (preg_match('/statut|check status/u', $title) !== 1 || preg_match('/Endpoint\s*:\s*GET\s+\S*\/status\b/u', $text) !== 1) {
                continue;
            }
            if (preg_match('/annul|rembours|supprim|modifi/u', Str::ascii(mb_strtolower($text))) === 1) {
                return null;
            }

            return ['answer' => 'L’API Check Status documentée sert à consulter le statut d’une transaction [SOURCE:'.$chunk->chunk_id.']. Les extraits fournis ne décrivent pas de commande d’annulation ou de modification pour cet endpoint ; je ne peux pas confirmer cette capacité.', 'chunk' => $chunk];
        }

        return null;
    }

    public function callbackConfiguration(string $question, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (! str_contains($flat, 'callback') || preg_match('/\b(?:declar\w*|configur\w*|enregistr\w*)\b/u', $flat) !== 1) {
            return null;
        }
        $configuration = $chunks->first(static fn ($c) => str_contains((string) data_get($c, 'contenu', ''), '## Étapes pour configurer un callback'));
        if ($configuration === null) {
            return null;
        }
        $text = explode('## Étapes pour configurer un callback', $configuration->contenu, 2)[1];
        $text = preg_replace('/^### (\d+)\.\s*/mu', '$1. ', trim($text)) ?? $text;
        $text .= ' [SOURCE:'.$configuration->chunk_id.'].';
        $sources = collect([$configuration]);
        if (str_contains($flat, 'code')) {
            $routing = $chunks->first(static fn ($c) => str_contains((string) data_get($c, 'contenu', ''), 'callback_url_code') && str_contains((string) data_get($c, 'contenu', ''), 'URL publiquement joignable'));
            if ($routing === null) {
                return null;
            }
            $text .= "\n\nLe champ `callback_url_code` désigne votre URL de notification dans les requêtes [SOURCE:".$routing->chunk_id.'].';
            $sources->push($routing);
        }

        return ['answer' => $text, 'chunk' => $configuration, 'chunks' => $sources];
    }

    public function httpsPolicy(string $question, Collection $chunks): ?array
    {
        if (! str_contains(mb_strtolower($question), 'https')) {
            return null;
        }
        $recommended = $chunks->first(static fn ($c) => preg_match('/HTTPS recommand[eé]e?/u', (string) data_get($c, 'contenu', '')) === 1);
        if ($recommended === null) {
            return null;
        }
        $binding = $chunks->filter(static fn ($c) => preg_match('/\bHTTP\b\s*:?\s*(?:est\s+)?(?:refus[eé]|interdit|non autoris[eé])|\bHTTPS\b\s*:?\s*(?:est\s+)?(?:strictement\s+)?obligatoire/iu', (string) data_get($c, 'contenu', '')) === 1);
        if ($binding->isNotEmpty()) {
            $rule = $binding->first();

            return ['answer' => 'Les extraits se contredisent : une page indique « HTTPS recommandée » [SOURCE:'.$recommended->chunk_id.'], tandis qu’une autre impose HTTPS ou refuse HTTP [SOURCE:'.$rule->chunk_id.']. Je ne peux pas résoudre ce conflit documentaire ; utilisez HTTPS et faites clarifier la règle.', 'chunk' => $recommended, 'chunks' => collect([$recommended, $rule])->unique('chunk_id')->values()];
        }
        $answer = 'La page de configuration du callback indique « HTTPS recommandée » [SOURCE:'.$recommended->chunk_id.'].';
        $sources = collect([$recommended]);
        $practice = $chunks->first(static fn ($c) => str_contains((string) data_get($c, 'contenu', ''), 'Utilisez systématiquement des URLs en HTTPS'));
        if ($practice !== null) {
            $answer .= ' Les bonnes pratiques demandent d’utiliser systématiquement HTTPS [SOURCE:'.$practice->chunk_id.'].';
            $sources->push($practice);
        }
        $answer .= ' Ces formulations ne permettent pas de confirmer un rejet technique de toutes les URL HTTP ; utilisez HTTPS pour suivre les recommandations.';

        return ['answer' => $answer, 'chunk' => $recommended, 'chunks' => $sources];
    }

    public function callbackRoles(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (preg_match('/x[- ]secret/u', $flat) !== 1 || ! str_contains(Str::ascii(mb_strtolower($resolvedQuestion)), 'callback')) {
            return null;
        }
        $routing = $chunks->first(static function ($c): bool {
            $text = (string) data_get($c, 'contenu', '');

            return preg_match('/callback_url_code[^\n]*Code de l.URL de callback/iu', $text) === 1
                || str_contains($text, 'callback_url_code') && str_contains($text, 'URL publiquement joignable')
                || str_contains($text, 'Callback') && str_contains($text, 'Récupérez le code généré');
        });
        $secret = $chunks->first(static fn ($c) => preg_match('/authentifi[^\n]*X-Secret/iu', (string) data_get($c, 'contenu', '')) === 1);
        if ($routing === null || $secret === null) {
            return null;
        }

        return ['answer' => '**Code callback** : `callback_url_code` identifie l’URL qui reçoit les notifications de transaction ; ce code ne remplace pas la clé secrète [SOURCE:'.$routing->chunk_id."].\n\n".'**X-Secret** : cet en-tête porte la clé secrète qui authentifie les appels aux API PVIT [SOURCE:'.$secret->chunk_id.'].', 'chunk' => $routing, 'chunks' => collect([$routing, $secret])->unique('chunk_id')->values()];
    }

    public function sandboxPlan(string $question, Collection $chunks): ?array
    {
        if ($this->sandboxRequirements($question, $chunks) === null) {
            return null;
        }
        $guide = $chunks->filter(static fn ($c) => str_contains(Str::ascii(mb_strtolower((string) data_get($c, 'document_titre', ''))), 'sandbox'));
        $patterns = [
            '/Code du Compte d.Op[eé]ration.*Token d.API/su',
            '/## 1\. Authentification/u',
            '/## 2\. Simulation de Transaction/u',
            '/Echo dynamique.*Condition de validation des tests/su',
            '/## 4\. Simulation de Transaction/u',
            '/## 5\. APIs Compl[eé]mentaires Requises/u',
        ];
        $steps = [];
        $sources = collect();
        foreach ($patterns as $index => $pattern) {
            $candidates = $guide->filter(static fn ($c) => preg_match($pattern, (string) data_get($c, 'contenu', '')) === 1);
            if ($index === 2) {
                $candidates = $candidates->reject(static fn ($c) => str_contains((string) data_get($c, 'section', ''), '>'));
            }
            if ($candidates->isEmpty() || $candidates->pluck('contenu')->unique()->count() !== 1) {
                return null;
            }
            $chunk = $candidates->first();
            $text = $chunk->contenu;
            if ($index === 0) {
                preg_match_all('/^- (.+)$/mu', $text, $instructions);
                $text = 'Identifiants de test : '.implode(' ', $instructions[1]);
            } elseif ($index === 1) {
                // This section configures the callback before generating the key; keep both.
                $text = preg_replace('/^## Pr[eé]requis[^\n]*\R/u', '', $text) ?? $text;
            } elseif ($index === 3 && preg_match('/^- \*\*Echo dynamique.*?(?=\R- |\z)/msu', $text, $echo) === 1) {
                $text = $echo[0];
            } elseif ($index === 5) {
                preg_match_all('/^### (API (?:KYC|Check Status)[^\n]*)/mu', $text, $apis);
                $text = preg_split('/^### API KYC/mu', $text)[0].' '.implode(' ; ', $apis[1]);
            }
            $text = preg_split('/### Exemple de requête|### R[eé]ponse attendue/u', $text)[0];
            $text = preg_replace('/^#{1,6}\s*(?:\d+\.\s*)?/mu', '', $text) ?? $text;
            $text = preg_replace('/```.*?```/su', '', $text) ?? $text;
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            $steps[] = ($index + 1).'. '.$text.' [SOURCE:'.$chunk->chunk_id.'].';
            $sources->push($chunk);
        }

        return ['answer' => implode("\n\n", $steps), 'chunk' => $sources->first(), 'chunks' => $sources];
    }

    public function callbackAcknowledgement(string $question, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if ((new ConversationActions)->declinesExamples($question) || preg_match('/\b(?:json|exemple|renvoyer|repondre)\b/u', $flat) !== 1 || ! str_contains($flat, 'callback') || ! str_contains($flat, 'reception')) {
            return null;
        }
        $matches = [];
        foreach ($chunks as $chunk) {
            $text = (string) data_get($chunk, 'contenu', '');
            if (preg_match('/Accus[eé] de r[eé]ception attendu de votre serveur(.*)/su', $text, $section) !== 1
                || preg_match('/HTTP\s+200/iu', $section[1]) !== 1
                || preg_match('/```json\s*(.*?)```/su', $section[1], $example) !== 1) {
                continue;
            }
            $json = json_decode(trim($example[1]), true);
            if (! is_array($json) || array_keys($json) !== ['transactionId', 'responseCode']) {
                continue;
            }
            $matches[json_encode($json)] = ['chunk' => $chunk, 'example' => trim($example[1])];
        }
        if (count($matches) !== 1) {
            return null;
        }
        $match = reset($matches);

        return ['answer' => 'Votre serveur répond HTTP 200 OK avec un écho dynamique : `transactionId` reprend celui reçu et `responseCode` reprend le champ `code` de la notification. Les valeurs ci-dessous sont celles de l’exemple documentaire ; ne les codez pas en dur [SOURCE:'.$match['chunk']->chunk_id."].\n\n```json\n".$match['example']."\n```", 'chunk' => $match['chunk']];
    }

    public function feesQueryExample(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (! str_contains(Str::ascii(mb_strtolower($resolvedQuestion)), 'frais') || ! (new ConversationActions)->requestsExample($question)) {
            return null;
        }
        foreach ($chunks as $chunk) {
            $text = (string) data_get($chunk, 'contenu', '');
            if (! str_contains(Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', ''))), 'frais')
                || preg_match('/Endpoint\s*:\s*GET\s+\/[^\s]+\/get-fees/u', $text) !== 1
                || ! str_contains($text, 'Paramètres de requête')) {
                continue;
            }
            $answer = 'Cet appel utilise GET et les paramètres de requête dans l’URL ; la documentation ne définit pas de corps JSON pour cet appel [SOURCE:'.$chunk->chunk_id.'].';
            if (preg_match('/```(?:bash|sh)\s*(curl.*?)```/su', $text, $curl) === 1) {
                $answer .= "\n\nExemple documentaire, avec des valeurs à remplacer [SOURCE:".$chunk->chunk_id."].\n\n```bash\n".trim($curl[1])."\n```";
            }

            return ['answer' => $answer, 'chunk' => $chunk];
        }

        return null;
    }

    public function sandboxRequirements(string $question, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (! str_contains($flat, 'sandbox') || preg_match('/\b(?:etapes?|prerequis|obligatoire|valider)\b/u', $flat) !== 1) {
            return null;
        }
        foreach ($chunks as $chunk) {
            $text = Str::ascii(mb_strtolower((string) data_get($chunk, 'contenu', '')));
            if (str_contains($text, 'kyc') && str_contains($text, 'check status') && str_contains($text, 'obligatoire') && str_contains($text, 'valider definitivement')) {
                return ['answer' => 'Pour valider définitivement l’intégration, les API KYC et Check Status sont obligatoires [SOURCE:'.$chunk->chunk_id.'].', 'chunk' => $chunk];
            }
        }

        return null;
    }

    /** @return array{answer: string, chunk: object}|null */
    public function endpoint(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (preg_match('/\b(?:endpoint|chemin|route|url de l.api)\b/u', $flat) !== 1 || preg_match('/\b(?:methode|http|verbe|get|post|put|delete|patch|head|options)\b/u', $flat) !== 1) {
            return null;
        }
        $words = static function (string $text): array {
            $tokens = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_unique(array_map(static fn ($word) => substr($word, 0, 5), array_filter($tokens, static fn ($word) => strlen($word) >= 4 && ! in_array($word, ['pvit', 'documentation', 'endpoint', 'methode', 'http', 'cette', 'comment', 'pour', 'dans', 'avec', 'juste', 'donner', 'parametres', 'verifier', 'verification', 'consulter', 'consultation', 'obtenir', 'recuperer', 'liste', 'presenter', 'expliquer'], true))));
        };
        $subject = $words($resolvedQuestion);
        $candidates = [];
        foreach ($chunks as $chunk) {
            $score = count(array_intersect($subject, $words((string) data_get($chunk, 'document_titre', ''))));
            $raw = str_replace(['*', '`'], '', (string) data_get($chunk, 'contenu', ''));
            if ($score === 0 || preg_match('/\bEndpoint\s*:\s*(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\/[^\s<>"\']+)/u', $raw, $match) !== 1) {
                continue;
            }
            $candidates[] = ['score' => $score, 'method' => $match[1], 'path' => $match[2], 'chunk' => $chunk];
        }
        if ($candidates === []) {
            return null;
        }
        $best = max(array_column($candidates, 'score'));
        $candidates = array_values(array_filter($candidates, static fn ($item) => $item['score'] === $best));
        if (count(array_unique(array_map(static fn ($item) => $item['method'].' '.$item['path'], $candidates))) !== 1) {
            return null;
        }
        $fact = $candidates[0];

        return ['answer' => $fact['method'].' '.$fact['path'].' [SOURCE:'.$fact['chunk']->chunk_id.'].', 'chunk' => $fact['chunk']];
    }

    /** @return array{answer: string, chunk: object}|null */
    public function exportAvailability(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        if (preg_match('/\b(?:combien|duree|temps|retention|disponib\w*|jours?|commenc\w*)\b/u', $flat) !== 1
            || preg_match('/\b(?:cle|secret|token|jeton|http)\b/u', $flat) === 1
            || preg_match('/\b(?:export|fichier|rapport)\b/u', Str::ascii(mb_strtolower($resolvedQuestion))) !== 1) {
            return null;
        }
        $facts = [];
        foreach ($chunks as $chunk) {
            $title = Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', '')));
            if (! str_contains($title, 'export')) {
                continue;
            }
            $text = str_replace(['*', '`'], '', Str::ascii(mb_strtolower((string) data_get($chunk, 'contenu', ''))));
            if (preg_match('/\bfichier\s+reste\s+disponible\s+(\d+)\s+jours?\s+apres\s+la\s+demande\b/u', $text, $match) === 1) {
                $facts[$match[1]] = $chunk;
            }
        }
        if (count($facts) !== 1) {
            return null;
        }
        $days = array_key_first($facts);
        $chunk = $facts[$days];

        return ['answer' => 'Le fichier d’export reste disponible '.$days.' jours après la demande [SOURCE:'.$chunk->chunk_id.'].', 'chunk' => $chunk];
    }

    /** @return array{answer: string, chunk: object}|null */
    public function expiredExport(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        if ((new ConversationActions)->requestedHttpError($question) !== '410') {
            return null;
        }
        $fact = $this->exportAvailability('Combien de jours le fichier reste-t-il disponible ?', $resolvedQuestion, $chunks);
        if ($fact === null || preg_match('/\b410\b.{0,35}\bexpir/iu', str_replace('*', '', $fact['chunk']->contenu)) !== 1) {
            return null;
        }

        $fact['answer'] = 'HTTP 410 indique que le fichier d’export a expiré. '.$fact['answer'];

        return $fact;
    }

    public function exportRenewal(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $downloadReset = str_contains(Str::ascii(mb_strtolower($resolvedQuestion)), 'export')
            && preg_match('/\b(?:repart\w*|recommenc\w*|reinitialis\w*|remis\w* a zero)\b/u', $flat) === 1
            && preg_match('/\btelecharg\w*\b/u', $flat) === 1;
        if (! $downloadReset && (! str_contains($flat, 'export') || preg_match('/\b(?:prolong\w*|renouv\w*)\b/u', $flat) !== 1)) {
            return null;
        }
        $exports = $chunks->filter(static fn ($chunk) => str_contains(Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', ''))), 'export'));
        if ($exports->contains(static fn ($chunk) => preg_match('/\b(?:prolong\w*|renouv\w*|repart\w*|reinitialis\w*)\b/u', Str::ascii(mb_strtolower((string) data_get($chunk, 'contenu', '')))) === 1)) {
            return null;
        }
        $fact = $this->exportAvailability('Combien de jours le fichier est-il disponible ?', $resolvedQuestion, $exports);
        if ($fact === null) {
            return null;
        }
        $fact['answer'] .= $downloadReset
            ? ' Les extraits fournis ne décrivent pas de remise à zéro du délai lors d’un téléchargement. Je ne peux donc pas confirmer ce renouvellement.'
            : ' Les extraits fournis ne décrivent pas de prolongation automatique de l’ancien lien lors d’une nouvelle demande. Je ne peux donc pas la confirmer.';

        return $fact;
    }

    /** @return array{answer: string, chunk: object}|null */
    public function credentialDuration(string $question, string $resolvedQuestion, Collection $chunks): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $context = Str::ascii(mb_strtolower($resolvedQuestion));
        if (preg_match('/\b(?:cle|secret|token|jeton)\b/u', $flat) !== 1
            || preg_match('/\b(?:meme|identique|difference|aussi)\b/u', $flat) === 1
            || (preg_match('/\b(?:combien|duree|valid\w*|expire|temps)\b/u', $flat) !== 1
                && ! (str_starts_with($flat, 'et ') && preg_match('/\b(?:duree|temps|conserve|jours)\b/u', $context) === 1))) {
            return null;
        }
        $values = [];
        foreach ($chunks as $chunk) {
            $text = (string) data_get($chunk, 'contenu', '');
            if (preg_match('/"secret"\s*:/u', $text) === 1 && preg_match('/"expires_in"\s*:\s*(\d+)/u', $text, $match) === 1) {
                $values[$match[1]] = $chunk;
            }
        }
        if (count($values) !== 1) {
            return null;
        }
        $seconds = array_key_first($values);
        $chunk = $values[$seconds];

        return ['answer' => 'La clé secrète reste valide '.$seconds.' secondes après sa génération [SOURCE:'.$chunk->chunk_id.'].', 'chunk' => $chunk];
    }
}
