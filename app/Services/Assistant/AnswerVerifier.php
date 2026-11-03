<?php

namespace App\Services\Assistant;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Verifies a generated answer against the documentation extracts the model was given, and
 * removes only what is demonstrably wrong.
 *
 * It replaces CitationGuard, which demanded a citation for every sentence and a close
 * paraphrase of the cited passage. Measured on the 2026-09-30 gold set, that rule removed correct
 * steps that were simply uncited, refused good answers over an uncited opening line, and still
 * let through the plausible errors that matter (an invented algorithm, "asynchronous" for a
 * synchronous API, a field called mandatory that is not). Here citations are optional: they only
 * number the sources. What is checked is what can be checked against the extracts:
 *
 * - specifics: every identifier, constant, header, path, link, quoted UI label and number the
 *   answer states must exist in the extracts (or in the question);
 * - contradictions: a field called mandatory or optional against its documented status,
 *   synchronous against asynchronous, an HTTP method against the documented one, "only JSON"
 *   when the extracts list alternatives, a documentation gap claimed about something documented;
 * - overstatements and guesses: a strong qualifier or an exclusivity the extracts never state
 *   for that subject, a hedged guess ("generalement sous Parametres"), a figure taken from the
 *   page of another feature;
 * - examples: a cURL/JSON example must use documented endpoints, headers, fields and values;
 *   placeholders and the question's own values are accepted, verbatim copying is not required;
 * - grounding: a long passage whose words are mostly absent from the extracts is general
 *   knowledge, not documentation.
 *
 * Everything else is kept, cited or not.
 */
class AnswerVerifier
{
    private const CITATION = '/\[\s*SOURCE\s*:\s*(\d+)\s*\]/iu';

    private const ANY_CITATION = '/\[\s*(?:SOURCE|DOCUMENT|CITATION)\b[^\]]*\]/iu';

    // Acronyms that are vocabulary, not documented values: never looked up in the extracts.
    private const COMMON_ACRONYMS = [
        'API', 'APIS', 'HTTP', 'HTTPS', 'JSON', 'XML', 'CSV', 'PDF', 'URL', 'URLS', 'URI', 'OTP', 'SMS', 'REST',
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'XAF', 'FCFA', 'PVIT', 'MYPVIT', 'IP', 'IPS', 'ID', 'IDS', 'KYC',
        'NIF', 'RCCM', 'UTC', 'ISO', 'MSISDN', 'SDK', 'CURL', 'HTML', 'UI', 'FAQ', 'SSL', 'TLS', 'DNS', 'IPV4',
        'IPV6', 'OK', 'NB', 'TTC', 'HT', 'FR', 'EN', 'PNG', 'JPEG', 'JPG', 'GIF', 'WEB', 'VISA', 'MASTERCARD',
        'PHP', 'CMS', 'WOOCOMMERCE', 'PRESTASHOP', 'WORDPRESS', 'NB', 'TEST', 'SANDBOX', 'PROD', 'IMPORTANT',
        'NOTE', 'ATTENTION', 'ETAPE', 'UUID', 'TOKEN', 'HEADER', 'HEADERS', 'BODY', 'ACC', 'SOURCE', 'SOURCES',
    ];

    private const STOP_WORDS = [
        'pour', 'avec', 'dans', 'votre', 'vous', 'cette', 'sont', 'etre', 'doit', 'peut', 'plus', 'tout', 'tous',
        'toute', 'leur', 'leurs', 'comme', 'elle', 'mais', 'donc', 'ainsi', 'apres', 'avant', 'chaque', 'entre',
        'sans', 'sous', 'vers', 'faut', 'notre', 'nous', 'ceux', 'celle', 'cela', 'ceci', 'dont', 'quand', 'aussi',
        'meme', 'lors', 'afin', 'avez', 'sera', 'seront', 'tres', 'bien', 'fait', 'faire', 'font', 'etes', 'cest',
        'suivant', 'suivante', 'suivantes', 'suit', 'voici', 'voila', 'exemple', 'pvit', 'mypvit', 'this', 'that',
        'with', 'from', 'your', 'have', 'will', 'what', 'when', 'into', 'selon', 'celui', 'ceux', 'quel', 'quelle',
        'quels', 'quelles', 'lequel', 'laquelle', 'ensuite', 'puis', 'enfin', 'alors', 'encore', 'deja', 'pouvez',
        'devez', 'permet', 'permettent', 'utiliser', 'utilisez', 'etape', 'etapes', 'documentation', 'extrait',
        'extraits', 'indique', 'precise', 'mentionne', 'decrit', 'suivez', 'voir', 'consultez', 'reponse',
    ];

    // Words so frequent in this corpus that sharing them says nothing about the subject.
    private const GENERIC_STEMS = [
        'transa', 'paieme', 'operat', 'compte', 'reques', 'champ', 'valeur', 'donnee', 'client', 'marcha',
        'api', 'apis', 'code', 'statut', 'status', 'type', 'secret', 'cle', 'url', 'urls', 'appel',
    ];

    private const HEDGES = '/\b(?:generalement|habituellement|en general|en principe|probablement|sans doute|vraisemblablement|typiquement|normalement|il est possible que|il se peut que|peut-etre|selon la version|selon votre version|dans la plupart des cas|en regle generale)\b/u';

    private const QUALIFIERS = [
        'asynchrone' => '/\basynchrone\w*/u',
        'synchrone' => '/\bsynchrone\w*/u',
        'idempotent' => '/\bidempoten\w*/u',
        'irreversible' => '/\birreversible\w*/u',
        'gratuit' => '/\bgratuit\w*/u',
        'illimite' => '/\billimite\w*/u',
        'permanent' => '/\bpermanent\w*/u',
        'temps reel' => '/\btemps reel\b/u',
        'instantane' => '/\binstantane\w*/u',
        'n expire jamais' => '/\bn expire (?:jamais|pas)\b/u',
    ];

    private const ANTONYMS = ['asynchrone' => 'synchrone', 'synchrone' => 'asynchrone'];

    private const EXCLUSIVITY = '/\b(?:uniquement|seulement|exclusivement|strictement|only|exclusively)\b/u';

    // Alternatives of one kind: "JSON only" is contradicted by an extract offering JSON or XML.
    private const ALTERNATIVE_CLASSES = [
        ['json', 'xml', 'csv', 'pdf', 'html'],
        ['get', 'post', 'put', 'patch', 'delete'],
        ['sms', 'email', 'e-mail', 'whatsapp'],
    ];

    // A negation in French prose: "ne ... pas", "n'a pas besoin", "jamais", "aucun".
    private const NEGATION = '/\b(?:ne|n)\b(?:\s+\S+){0,4}?\s+(?:pas|jamais|plus|aucun\w*|rien|guere)\b|\bjamais\b|\baucune?s?\b|\bpas (?:de|d|besoin)\b|\binterdit\w*\b/u';

    // An obligation, and its dispensation: "votre serveur n a pas besoin de repondre 200" against
    // "votre serveur doit imperativement repondre ... HTTP 200".
    private const OBLIGATION = '/\b(?:doit|doivent|devez|devra|obligatoire\w*|imperativement|requis|requise|il faut)\b/u';

    private const DISPENSATION = '/\b(?:pas besoin|pas necessaire\w*|pas obligatoire\w*|facultati\w*|optionnel\w*|inutile\w*|pas requis\w*|n y a pas lieu)\b/u';

    // Words that grant: "les URL localhost sont acceptees" against "ne seront pas valides".
    private const PERMISSION = '/\b(?:accepte\w*|autorise\w*|valides?|supporte\w*|permis\w*|admis\w*|fonctionne\w*|possible)\b/u';

    // A passage the documentation itself disavows, before or after it.
    private const DISAVOWAL_AFTER = '/\b(?:affirmation|information|phrase|declaration|consigne|texte|ceci|cela)\b.{0,30}\b(?:fausse|faux|incorrecte?|erronee?)\b|\b(?:ceci|cela) est (?:faux|errone|incorrect)\b|\binformation incorrecte\b|\bne doit pas etre suivie\b|\bne faites pas cela\b|\bne suivez jamais cette approche\b|\bcorrection\b/u';

    private const DISAVOWAL_BEFORE = '/^\s*(?:exemple incorrect|mauvais exemple|mauvaise pratique|a eviter|incorrect)\s*:?\s*$/u';

    private const NAVIGATION_CUES = '/\b(?:cliquez|cliquer|selectionnez|choisissez|allez|accedez|rendez[ -]vous|ouvrez|menu|onglet|bouton|rubrique|section|page)\b/u';

    private const UNIT_WORDS = '(%|fois|tentatives?|essais?|jours?|j|heures?|h|minutes?|min|mn|secondes?|sec|s|ms|mois|ans?|annees?|caracteres?|car|lignes?|exports?|mo|ko|go|xaf|fcfa|centimes?|euros?)';

    // Units of an answer are removed only above this share; beyond it the answer is too damaged
    // to stand without a retry.
    // Payment methods and wallets a model tends to list from general knowledge ("Mobile Money
    // (Airtel Money, Moov Money, Orange Money, MTN Mobile Money)"). Naming one the extracts never
    // mention tells the developer PVit supports it; each is checked against the extracts and the
    // question. Longer names first, so "MTN Mobile Money" is matched before "MoMo".
    private const PAYMENT_METHODS = [
        'mtn mobile money', 'orange money', 'airtel money', 'moov money', 'free money', 'express union',
        'western union', 'american express', 'apple pay', 'google pay', 'samsung pay', 'mtn momo',
        'mobicash', 'mastercard', 'unionpay', 'moneygram', 'paypal', 'stripe', 'm-pesa', 'mpesa',
        't-money', 'flooz', 'wave', 'momo', 'amex', 'visa', 'gimac',
    ];

    private const MAX_REMOVED_SHARE = 0.5;

    // A passage of at least this many content words must share this share of them with the
    // extracts, or it is general knowledge rather than documentation.
    private const GROUNDING_MIN_WORDS = 12;

    private const GROUNDING_MIN_COVERAGE = 0.4;

    // A shorter passage is only held to a much lower bar: almost none of its words documented.
    private const GROUNDING_SHORT_MIN_WORDS = 5;

    private const GROUNDING_SHORT_MIN_COVERAGE = 0.25;

    private ResponseGuard $responseGuard;

    /** @var array<string, mixed> Index of the extracts of the verify() call in progress. */
    private array $context = [];

    private array $foreignSourceIds = [];

    public function __construct(?ResponseGuard $responseGuard = null)
    {
        $this->responseGuard = $responseGuard ?? new ResponseGuard;
    }

    /**
     * @param  Collection<int, object>  $chunks  The extracts the model was given (chunk_id, contenu, document_titre, section).
     * @param  string  $question  Its values (an amount, a percentage, a date) may appear in the answer.
     */
    public function verify(string $answer, Collection $chunks, string $question = ''): VerifiedAnswer
    {
        $scoped = (new WebhookScope)->select($chunks, $question);
        $this->foreignSourceIds = array_diff($chunks->pluck('chunk_id')->all(), $scoped->pluck('chunk_id')->all());
        $chunks = $scoped;
        $this->context = $this->index($chunks, $question);
        $answer = $this->normalizeCodeFences($this->unwrapDocumentedLinks($this->normalizeCitationFormat($answer)));
        // Tolerating a comment during analysis must not leave an invalid application/json
        // example in the displayed answer. Only remove comments; retain all keys and values.
        $answer = preg_replace_callback('/^([ \t]*)(`{3,}|~{3,})json[ \t]*\R([\s\S]*?)^[ \t]*\2[ \t]*$/mu', function (array $match): string {
            $body = rtrim($this->withoutJsonComments($match[3]), "\r\n");

            return $match[1].$match[2]."json\n".$body."\n".$match[1].$match[2];
        }, $answer) ?? $answer;
        $removed = [];
        $blocks = [];
        $substantive = 0;
        $kept = 0;
        $openingSeen = false;
        $openingRemoved = false;
        $pendingLeadIn = null;

        foreach ($this->answerBlocks($answer) as $block) {
            if ($block['kind'] === 'code') {
                $pendingLeadIn = null;
                $issue = $this->exampleIssue($block['text']);

                if ($issue !== null) {
                    $removed[] = ['text' => $block['text'], 'reason' => $issue[0], 'detail' => $issue[1]];
                }

                $blocks[] = ['kind' => 'code', 'text' => $block['text'], 'dropped' => $issue !== null];

                continue;
            }

            $units = [];
            // A list introduced by "Les champs obligatoires sont :" inherits that claim, whether
            // the lead-in opens the list's own block or the block just before it.
            $leadIn = $pendingLeadIn;
            $pendingLeadIn = null;
            $blockUnits = $this->blockUnits($block['text']);

            foreach ($blockUnits as $position => $unit) {
                $role = $this->role($unit);
                $endsWithColon = preg_match('/:\s*(?:\*\*)?\s*$/u', trim($this->withoutMarkers($unit))) === 1;

                if ($position === 0 && $endsWithColon) {
                    $leadIn = $unit;
                }

                if ($position === count($blockUnits) - 1 && $endsWithColon) {
                    $pendingLeadIn = $unit;
                }

                $inherited = $position > 0 || $leadIn !== $unit ? $leadIn : null;
                $issue = $this->unitIssue($unit, $role, $this->isListItem($unit) ? $inherited : null);

                if ($issue !== null && isset($issue[2])) {
                    // A guess in parentheses is cut out; the rest of the unit stands.
                    $removed[] = ['text' => $issue[2], 'reason' => $issue[0], 'detail' => $issue[1]];
                    $unit = $issue[3];
                    $issue = $this->unitIssue($unit, $role, $this->isListItem($unit) ? $inherited : null);
                }

                $isSubstantive = $role === 'substantive';

                if ($isSubstantive) {
                    $substantive++;
                }

                if ($issue !== null) {
                    $removed[] = ['text' => $unit, 'reason' => $issue[0], 'detail' => $issue[1]];

                    if ($isSubstantive && ! $openingSeen && ! $this->isListItem($unit)) {
                        $openingRemoved = true;
                    }
                } else {
                    $units[] = ['text' => $unit, 'structural' => $role === 'structural'];
                    $kept += $isSubstantive ? 1 : 0;
                }

                if ($isSubstantive) {
                    $openingSeen = true;
                }
            }

            $blocks[] = ['kind' => 'text', 'units' => $units];
        }

        $verified = $this->assemble($blocks);
        $citedIds = $this->citedIds($verified);
        $removedShare = $substantive > 0 ? ($substantive - $kept) / $substantive : 0.0;
        $reason = match (true) {
            trim($verified) === '' || $kept === 0 => 'nothing_left',
            $openingRemoved => 'opening_removed',
            $removedShare > self::MAX_REMOVED_SHARE => 'too_much_removed',
            default => null,
        };
        if (in_array('protocol_contradiction', array_column($removed, 'reason'), true)) {
            $reason = 'protocol_contradiction';
        }
        if (in_array('incomplete_answer', array_column($removed, 'reason'), true)) {
            $reason = 'incomplete_answer';
        }
        if (preg_match('/^\s*\|/mu', $verified) === 1) {
            $rows = array_values(array_filter(preg_split('/\R/u', $verified) ?: [], static fn ($line) => preg_match('/^\s*\|.*\|/u', $line) === 1));
            $separator = false;
            $data = 0;
            foreach ($rows as $row) {
                if (preg_match('/^\s*\|[\s:|\-]+\|\s*$/u', $row) === 1) {
                    $separator = true;
                } elseif ($separator) {
                    $data++;
                }
            }
            if (! $separator || $data === 0) {
                $reason = 'incomplete_table';
            }
        }

        // A generic-knowledge disclaimer can introduce otherwise plausible bullets. Treat the
        // whole draft as unsupported so a retry cannot keep those bullets without their caveat.
        $generalAdvice = '/\b(?:selon (?:les|des) (?:regles|principes|pratiques) gener\w*|d apres les bonnes pratiques|de maniere generale)\b/u';
        if (preg_match($generalAdvice, $this->flat($answer), $match) === 1
            && preg_match($generalAdvice, $this->context['flat']) !== 1) {
            $removed[] = ['text' => $answer, 'reason' => 'speculation', 'detail' => $match[0]];
            $reason = 'unsupported_general_advice';
        }

        return new VerifiedAnswer(
            answer: $verified,
            removed: $removed,
            keptUnits: $kept,
            substantiveUnits: $substantive,
            acceptable: $reason === null,
            reason: $reason,
            citedIds: $citedIds,
            supportingIds: $citedIds !== [] ? $citedIds : $this->supportingIds($verified, $chunks),
        );
    }

    /**
     * Citation markers renumbered [1], [2]... in order of first use, with their chunks in that
     * order. Markers of unknown chunks are dropped. An answer without any marker gets the chunks
     * it matches best as its sources, so its source list is never empty.
     *
     * @return array{answer: string, sources: list<object>}
     */
    public function renderNumbered(string $answer, Collection $chunks): array
    {
        $answer = $this->withoutOrphanedCitations($this->normalizeCitationFormat($answer));
        $byId = $chunks->keyBy(static fn ($chunk): int => (int) $chunk->chunk_id);
        $numbers = [];

        $answer = (string) preg_replace_callback(self::CITATION, static function (array $match) use (&$numbers, $byId): string {
            $id = (int) $match[1];

            if (! $byId->has($id)) {
                return '';
            }

            $numbers[$id] ??= count($numbers) + 1;

            return '['.$numbers[$id].']';
        }, $answer);
        $answer = (string) preg_replace('/\[(\d+)\](?:\s*\[\1\])+/u', '[$1]', $answer);
        // A marker the model put on a line of its own belongs to the line above it.
        $answer = (string) preg_replace('/[ \t]*\R[ \t]*((?:\[\d+\][ \t]*)+)\.?[ \t]*$/mu', ' $1', $answer);
        $answer = (string) preg_replace('/\]\s+\[(?=\d+\])/u', '][', $answer);
        $answer = (string) preg_replace('/[ \t]+(\[\d+\])/u', ' $1', $answer);
        asort($numbers);
        $sources = array_values(array_map(static fn (int $id): object => $byId->get($id), array_keys($numbers)));

        if ($sources === []) {
            $sources = array_values(array_filter(array_map(
                static fn (int $id): ?object => $byId->get($id),
                $this->supportingIds($answer, $chunks),
            )));
        }

        return ['answer' => $this->normalizeCodeFences(trim($answer)), 'sources' => $sources];
    }

    /**
     * Citation markers replaced by a readable "[Document / Section]" label (the /assistant
     * rendering).
     */
    public function renderLabels(string $answer, Collection $chunks): string
    {
        $labels = $chunks
            ->keyBy(static fn ($chunk): int => (int) $chunk->chunk_id)
            ->map(fn ($chunk): string => $this->citationLabel((string) $chunk->document_titre, (string) ($chunk->section ?: 'Section non précisée')));

        return $this->normalizeCodeFences(trim((string) preg_replace_callback(
            self::CITATION,
            static fn (array $match): string => $labels->has((int) $match[1]) ? '['.$labels->get((int) $match[1]).']' : '',
            $this->normalizeCodeFences($this->normalizeCitationFormat($answer)),
        )));
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'fabricated_identifier' => 'identifiant absent des extraits',
            'fabricated_value' => 'valeur chiffree absente des extraits',
            'fabricated_link' => 'lien ou domaine absent des extraits',
            'fabricated_label' => 'libelle d interface absent des extraits',
            'unknown_source' => 'cite un extrait inexistant',
            'contradiction' => 'contredit les extraits',
            'unsupported_qualifier' => 'qualificatif que les extraits n emploient pas pour ce sujet',
            'misattributed_detail' => 'chiffre tire de la page d un autre sujet',
            'speculation' => 'supposition non documentee',
            'ungrounded' => 'contenu absent des extraits',
            'invalid_example' => 'exemple non conforme a la documentation',
            'code_block' => 'bloc de code qui n est pas un exemple d API documente',
            'undocumented_payment_method' => 'moyen de paiement absent des extraits',
            default => $reason,
        };
    }

    /**
     * The removed passages as a list for a correction prompt, with the reason for each.
     *
     * @param  list<array{text: string, reason: string, detail?: string}>  $removed
     */
    public static function describeRemovals(array $removed, int $limit = 8): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => '- "'.Str::limit(trim((string) preg_replace('/\s+/u', ' ', $item['text'])), 220).'" ('
                .self::reasonLabel($item['reason']).(($item['detail'] ?? '') !== '' ? ' : '.$item['detail'] : '').')',
            array_slice($removed, 0, $limit),
        ));
    }

    // ---------------------------------------------------------------------------------------
    // Index of the extracts
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function index(Collection $chunks, string $question): array
    {
        $entries = [];
        $df = [];

        foreach ($chunks->values() as $chunk) {
            $raw = (string) data_get($chunk, 'contenu', '');
            $stems = array_fill_keys($this->contentStems($raw.' '.data_get($chunk, 'section', '')), true);

            foreach (array_keys($stems) as $stem) {
                $df[$stem] = ($df[$stem] ?? 0) + 1;
            }

            $entries[] = [
                'id' => (int) data_get($chunk, 'chunk_id'),
                'raw' => $raw,
                'flat' => $this->flat($raw),
                'stems' => $stems,
                'title' => (string) data_get($chunk, 'document_titre', ''),
                'pairs' => $this->numberFacts($raw)['pairs'],
            ];
        }

        $raw = implode("\n", array_column($entries, 'raw'));
        $facts = $this->numberFacts($raw);
        $questionFacts = $this->numberFacts($question);

        return [
            'chunks' => $entries,
            'ids' => array_fill_keys(array_column($entries, 'id'), true),
            'raw' => $raw,
            'raw_lower' => Str::ascii(mb_strtolower($raw)),
            'flat' => $this->flat($raw.' '.implode(' ', array_column($entries, 'title'))),
            'question_flat' => $this->flat($question),
            'numbers' => $facts['numbers'] + $questionFacts['numbers'],
            'pairs' => $facts['pairs'] + $questionFacts['pairs'],
            'stems' => array_fill_keys(array_merge(
                $this->contentStems($raw.' '.implode(' ', array_column($entries, 'title'))),
                $this->contentStems($question),
            ), true),
            'df' => $df,
            'fields' => $this->fieldStatuses($raw),
            'sentences' => $this->indexSentences($entries),
            'table_rows' => array_values(array_filter(
                preg_split('/\R/u', $raw) ?: [],
                static fn (string $line): bool => substr_count($line, '|') >= 2,
            )),
            'endpoints' => $this->endpoints($raw),
            'title_stems' => collect($entries)->pluck('title')->filter()->unique()
                ->mapWithKeys(fn (string $title): array => [$title => $this->contentStems($title)])->all(),
        ];
    }

    /**
     * Every sentence of the extracts with its content words, whether it is negative, and whether
     * the documentation disavows it ("Exemple incorrect", "Cette affirmation est fausse").
     *
     * @return list<array{flat: string, stems: array<string, true>, negative: bool, disavowed: bool}>
     */
    private function indexSentences(array $entries): array
    {
        $sentences = [];

        foreach ($entries as $entry) {
            $pieces = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?;])\s+|\R/u', $entry['raw']) ?: [])));

            foreach ($pieces as $i => $piece) {
                $flat = $this->flat($piece);
                $previous = $i > 0 ? trim($this->flat((string) preg_replace('/[*_#]/u', '', $pieces[$i - 1]))) : '';
                $next = $pieces[$i + 1] ?? '';

                $sentences[] = [
                    'flat' => $flat,
                    'stems' => array_fill_keys($this->contentStems($piece), true),
                    'negative' => preg_match(self::NEGATION, $flat) === 1,
                    'disavowed' => preg_match(self::DISAVOWAL_BEFORE, $previous) === 1
                        || ($next !== '' && preg_match(self::DISAVOWAL_AFTER, $this->flat($next)) === 1
                            && preg_match(self::DISAVOWAL_AFTER, $flat) !== 1),
                ];
            }
        }

        return $sentences;
    }

    /**
     * Documented status of every field of a parameter list ("- amount (number, Obligatoire) --").
     *
     * @return array<string, list<'M'|'C'|'O'|'U'>>
     */
    private function fieldStatuses(string $raw): array
    {
        $fields = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s*`?([A-Za-z][\w.\-]*)`?\s*\(([^)]*)\)\s*(.*)$/u', $line, $match) !== 1) {
                continue;
            }

            $attributes = $this->flat($match[2]);
            $description = $this->flat($match[3]);
            $fields[strtolower($match[1])][] = match (true) {
                preg_match('/\b(?:conditionnel\w*|requise? si|obligatoire si)\b/u', $attributes.' '.$description) === 1 => 'C',
                preg_match('/\b(?:obligatoire|required|requis|requise)\b/u', $attributes) === 1 => 'M',
                preg_match('/\b(?:optionnel\w*|facultati\w*|optional)\b/u', $attributes.' '.$description) === 1 => 'O',
                default => 'U',
            };
        }

        return $fields;
    }

    /**
     * Documented endpoints: normalized path template => HTTP methods ([] when the extracts only
     * give the path).
     *
     * @return array<string, list<string>>
     */
    private function endpoints(string $raw): array
    {
        $endpoints = [];

        preg_match_all('/\b(GET|POST|PUT|PATCH|DELETE)\s+"?(?:https?:\/\/[^\/\s"]+)?(\/[^\s"\'`]+)/u', $raw, $withMethod, PREG_SET_ORDER);

        foreach ($withMethod as $match) {
            $template = $this->pathTemplate($match[2]);

            if ($template !== '') {
                $endpoints[$template][] = $match[1];
            }
        }

        preg_match_all('/(?:https?:\/\/)?(?:[a-z0-9-]+\.)+(?:com|net|org|io|pro|dev|fr|ga)(\/[^\s"\'`<>)]*)?/iu', $raw, $urls);

        foreach ($urls[0] as $url) {
            $template = $this->pathTemplate((string) parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_PATH));

            if ($template !== '') {
                $endpoints[$template] ??= [];
            }
        }

        return array_map(static fn (array $methods): array => array_values(array_unique($methods)), $endpoints);
    }

    // ---------------------------------------------------------------------------------------
    // Checks of one prose unit
    // ---------------------------------------------------------------------------------------

    /**
     * @return 'structural'|'courtesy'|'abstention'|'substantive'
     */
    private function role(string $unit): string
    {
        $text = trim((string) preg_replace(self::CITATION, '', $unit));
        $plain = trim((string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+|[*#>`]/u', '', $text));

        if ($plain === '' || preg_match('/^#{1,6}\s/u', $text) === 1
            || (mb_strlen($plain) <= 120 && preg_match('/:\s*$/u', $plain) === 1 && $this->atoms($plain) === [])
            || (mb_strlen($plain) <= 60 && preg_match('/^\*\*[^*]+\*\*\s*:?\s*$/u', trim((string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $text))) === 1)) {
            return 'structural';
        }

        $flat = $this->flat($plain);

        if (mb_strlen($plain) <= 160 && $this->atoms($plain) === [] && preg_match(
            '/\b(?:n hesitez pas|bonne integration|j espere|si vous avez d autres questions|besoin d aide|je reste a (?:votre )?disposition)\b/u',
            $flat,
        ) === 1) {
            return 'courtesy';
        }

        if (mb_strlen($plain) <= 300 && $this->isGapStatement($flat)) {
            return 'abstention';
        }

        return 'substantive';
    }

    /**
     * The first error found in a unit, as [reason, detail], or, for a guess in parentheses that
     * can be cut out, [reason, detail, removed text, unit without it]. Null when nothing is wrong.
     *
     * @return array{0: string, 1: string, 2?: string, 3?: string}|null
     */
    private function unitIssue(string $unit, string $role, ?string $leadIn): ?array
    {
        if (preg_match_all('/(?<!\\\\)`/u', $unit) % 2 !== 0) {
            return ['incomplete_answer', 'Un fragment de code en ligne est interrompu.'];
        }
        if (($issue = $this->jsonFieldCountIssue($unit)) !== null) {
            return $issue;
        }
        if (preg_match('/\A\s*[{}\[\],]+\s*\z/u', $this->withoutMarkers($unit)) === 1) {
            return ['invalid_example', 'fragment JSON isole'];
        }
        if ($role === 'structural' || $role === 'courtesy') {
            return $this->linkIssue($unit);
        }

        $ids = $this->markerIds($unit);

        if ($ids !== [] && array_filter($ids, fn (int $id): bool => isset($this->context['ids'][$id])) === []) {
            if (array_diff($ids, $this->foreignSourceIds) === []) {
                return ['misattributed_detail', 'La source citée appartient à un autre contrat de webhook.'];
            }

            return ['unknown_source', 'SOURCE:'.implode(', SOURCE:', $ids)];
        }

        if (($issue = $this->linkIssue($unit)) !== null) {
            return $issue;
        }

        $text = $this->proseText($unit);

        if ($role === 'abstention') {
            return $this->paymentMethodIssue($unit)
                ?? $this->falseGapIssue($text)
                ?? $this->identifierIssue($unit)
                ?? $this->numberIssue($text);
        }

        return $this->paymentMethodIssue($unit)
            ?? $this->webhookSecretIssue($text)
            ?? $this->markupIssue($unit)
            ?? $this->speculationIssue($unit, $text)
            ?? $this->identifierIssue($unit)
            ?? $this->credentialRoleIssue($text)
            ?? $this->apiCapabilityIssue($text, $leadIn)
            ?? $this->integrationRequirementIssue($text)
            ?? $this->numberIssue($text)
            ?? $this->credentialDurationIssue($text)
            ?? $this->currencyIssue($text)
            ?? $this->pairingIssue($text)
            ?? $this->labelIssue($unit, $text)
            ?? $this->requirementIssue($text, $leadIn)
            ?? $this->methodIssue($text)
            ?? $this->qualifierIssue($text)
            ?? $this->exclusivityIssue($text)
            ?? $this->disavowedIssue($text)
            ?? $this->polarityIssue($unit, $text)
            ?? $this->groundingIssue($text);
    }

    private function jsonFieldCountIssue(string $unit): ?array
    {
        $flat = Str::ascii(mb_strtolower($unit));
        $flat = preg_replace('/[*`]/u', '', $flat) ?? $flat;
        if (preg_match('/\b(?:json|accuse|notification)\b/u', $flat) !== 1
            || preg_match('/\b(\d+|un|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\s+champs?\s+obligatoires?\b/u', $flat, $count) !== 1
            || preg_match('/\b(?:ne\s+(?:necessit\w*|demand\w*|contien\w*|comprend\w*|requiert)|n[’\'](?:exig\w*|impos\w*))\s+pas\s+(?:de\s+)?'.preg_quote($count[0], '/').'/u', $flat) === 1) {
            return null;
        }
        $numbers = ['un' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10];
        $expected = $numbers[$count[1]] ?? (int) $count[1];
        $json = new JsonExamples;
        $ranges = [];
        foreach ($this->context['chunks'] ?? [] as $entry) {
            if (str_contains($flat, 'reporting') && ! str_contains(Str::ascii(mb_strtolower($entry['title'])), 'reporting')) {
                continue;
            }
            $examples = $json->select($json->extract($entry['raw']), $unit);
            $statuses = $this->fieldStatuses($entry['raw']);
            foreach ($examples as $example) {
                $mandatory = 0;
                $optional = 0;
                foreach ($example['keys'] as $key) {
                    $status = array_unique($statuses[strtolower($key)] ?? []);
                    $mandatory += $status === ['M'] ? 1 : 0;
                    $optional += $status === ['O'] ? 1 : 0;
                }
                $ranges[] = [$mandatory, count($example['keys']) - $optional];
            }
            if ($examples === [] && str_contains(Str::ascii(mb_strtolower($entry['raw'])), $count[0])) {
                return null;
            }
        }

        foreach ($ranges as [$minimum, $maximum]) {
            if ($expected >= $minimum && $expected <= $maximum) {
                return null;
            }
        }

        return ['protocol_contradiction', 'Le nombre de champs obligatoires annoncé n’est pas établi pour ce contrat JSON.'];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function linkIssue(string $unit): ?array
    {
        preg_match_all('/\b(?:https?|ftp):\/\/\S+|\bwww\.\S+|\b(?:[a-z0-9-]+\.)+(?:com|net|org|io|pro|dev|fr|ga|cm|africa)(?:\/\S*)?/iu', $unit, $matches);

        foreach ($matches[0] as $reference) {
            $reference = Str::ascii(mb_strtolower(rtrim($reference, '.,;:!?)]`*_"\'')));

            if ($this->isPlaceholderHost($reference)) {
                continue; // "https://votre-domaine.com/api/callback" stands for the reader's own URL
            }
            $bare = (string) preg_replace('/^(?:https?:\/\/)?(?:www\.)?/u', '', rtrim($reference, '/'));
            $host = (string) (parse_url(str_contains($reference, '://') ? $reference : 'https://'.$reference, PHP_URL_HOST) ?: '');
            $path = (string) (parse_url(str_contains($reference, '://') ? $reference : 'https://'.$reference, PHP_URL_PATH) ?: '');

            if (str_contains($this->context['raw_lower'], $bare)) {
                continue;
            }

            $root = $this->rootDomain($host);
            $officialHost = in_array($root, $this->officialRootDomains(), true) || str_contains($this->context['raw_lower'], $root);

            if ($officialHost && (trim($path, '/') === '' || $this->pathDocumented($path))) {
                continue;
            }

            return ['fabricated_link', $bare];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function identifierIssue(string $unit): ?array
    {
        foreach ($this->atoms($this->withoutMarkers($unit)) as $atom) {
            if ($this->isPlaceholder($atom)) {
                continue;
            }

            if (str_starts_with($atom, '/')) {
                // A path the extracts quote as it is ("Exemple: /UILUQWSEOWFCE5G/status") is
                // documented even when no endpoint line declares it.
                if (! str_contains($this->context['raw_lower'], Str::ascii(mb_strtolower($atom))) && ! $this->pathDocumented($atom)) {
                    return ['fabricated_identifier', $atom];
                }

                continue;
            }

            if (! $this->present($atom)) {
                return ['fabricated_identifier', $atom];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function integrationRequirementIssue(string $text): ?array
    {
        $flat = $this->flat($text);
        $documented = $this->context['flat'];
        if (str_contains($documented, 'valider definitivement') && str_contains($documented, 'kyc')
            && str_contains($documented, 'check status') && str_contains($documented, 'obligatoire')
            && preg_match('/\b(?:check status|kyc)\b/u', $flat) === 1
            && preg_match(self::DISPENSATION, $flat) === 1) {
            return ['protocol_contradiction', 'KYC et Check Status sont obligatoires pour valider définitivement l’intégration.'];
        }

        return null;
    }

    private function apiCapabilityIssue(string $text, ?string $leadIn): ?array
    {
        $clauses = preg_split('/(?<=[.!?])\s+|\b(?:mais|cependant|en revanche)\b/iu', $text) ?: [$text];
        if (count($clauses) > 1) {
            foreach ($clauses as $clause) {
                if (($issue = $this->apiCapabilityIssue($clause, $leadIn)) !== null) {
                    return $issue;
                }
            }

            return null;
        }
        $flat = $this->flat(($leadIn ?? '').' '.$text);
        $action = '/\b(?:annulez|annuler|suspendez|suspendre|supprimez|supprimer|remboursez|rembourser|modifiez|modifier)\b/u';
        if ($this->hasPositivePredicate($flat, $action)
            && preg_match('/\b(?:api de statut|api de status|api de verification|api check status|check status)\b/u', $flat) === 1) {
            $evidence = collect($this->context['sentences'])->contains(fn ($sentence): bool => $this->hasPositivePredicate($sentence['flat'], $action)
                && preg_match('/\b(?:api de statut|api de status|check status)\b/u', $sentence['flat']) === 1
            );
            if (! $evidence) {
                return ['unsupported_capability', 'Une consultation de statut ne prouve pas une capacité d’annulation, de remboursement ou de modification.'];
            }
        }
        $claim = $this->flat($text);
        $incoming = preg_match('/\bx-secret\b.{0,70}\b(?:fourni|envoye|present)\w*\b.{0,70}\b(?:notification|callback|webhook)\w*\b/u', $claim) === 1
            || preg_match('/\b(?:valid\w*|verifi\w*|authentifi\w*)\b.{0,45}\bx-secret\b/u', $claim) === 1
                && (str_contains($this->context['question_flat'], 'callback') || preg_match('/\b(?:callback|notification|webhook)\w*\b/u', $flat) === 1);
        if ($incoming && str_contains($claim, 'x-secret')
            && $this->hasPositivePredicate($claim, '/\b(?:valid\w*|verifi\w*|authentifi\w*|fourni\w*|envoye\w*|present\w*)\b/u')
            && preg_match('/\b(?:mes appels|vos appels|appels aux api|appels api|requetes api|requetes d.initiation|requete de paiement|lors des requetes)\b/u', $claim) !== 1) {
            $evidence = collect($this->context['sentences'])->contains(fn ($sentence): bool => str_contains($sentence['flat'], 'x-secret')
                && preg_match('/\b(?:callback|notification|webhook)\w*\b/u', $sentence['flat']) === 1
                && preg_match('/\b(?:authentifi\w*|valid\w*|verifi\w*|fourni\w*)\b/u', $sentence['flat']) === 1
                && preg_match(self::NEGATION, $sentence['flat']) !== 1);
            if (! $evidence) {
                return ['unsupported_capability', 'X-Secret pour les appels aux API ne prouve pas sa présence dans un callback entrant.'];
            }
        }

        return null;
    }

    private function hasPositivePredicate(string $text, string $pattern): bool
    {
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$verb,$offset]) {
            $prefix = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($verb));
            $negativeBefore = preg_match('/\b(?:ne|n)\s*(?:(?:peut|permet|doit|devrait|pouvez|devez|faut|sert|est|sont)\s+)?(?:pas|jamais)\s+(?:(?:de|d|a|le|la|les|l|un|une|cette|votre)\s+){0,3}$/u', $prefix) === 1;
            $negativeAfter = preg_match('/\b(?:ne|n)\s*$/u', $prefix) === 1 && preg_match('/^\s+(?:pas|jamais)\b/u', $after) === 1;
            $negativeTool = preg_match('/\b(?:ne|n)\s+utilisez\s+pas\b.{0,50}\b(?:api de statut|check status)\b.{0,12}\bpour\s*$/u', $prefix) === 1;
            if (! $negativeBefore && ! $negativeAfter && ! $negativeTool) {
                return true;
            }
        }

        return false;
    }

    private function credentialRoleIssue(string $text): ?array
    {
        $flat = $this->flat($text);
        foreach (preg_split('/[.!?;]|\R/u', $text) ?: [] as $sentence) {
            $clause = $this->flat($sentence);
            if (preg_match('/\bcode (?:de l |de la |du )?url\b(?:(?!\b(?:chemin|route)\b).){0,100}\b(?:sont|est|doivent|doit)\b(?:(?!\b(?:chemin|route)\b).){0,70}\b(?:en[- ]tetes?|headers?)\b/u', $clause) === 1
                && preg_match('/\b(?:ne|pas|aucun|sans)\b/u', $clause) !== 1) {
                return ['protocol_contradiction', 'Le code URL est un parametre de chemin, pas un en-tete.'];
            }
        }
        if (preg_match('/\b(?:code url|code callback|callback_url_code)\b.{0,260}\b(?:en[- ]tetes?|headers?)\b/u', $flat) === 1
            && preg_match('/\b(?:ne|n)\b.{0,30}\bpas\b.{0,200}\b(?:en[- ]tetes?|headers?)\b/u', $flat) !== 1
            && preg_match('/\b(?:x-secret|cle secrete)\b.{0,60}\b(?:en[- ]tetes?|headers?)\b|\b(?:en[- ]tetes?|headers?)\b.{0,40}\bx-secret\b/u', $flat) !== 1) {
            return ['protocol_contradiction', 'Le code URL est un identifiant de routage, pas une valeur d en-tete ; X-Secret porte la cle secrete.'];
        }
        $referent = null;
        foreach (preg_split('/(?<=[.!?])\s+|\R|\b(?:mais|cependant|en revanche|tandis que)\b/iu', $text) ?: [] as $sentence) {
            $clause = $this->flat($sentence);
            $routingPattern = '/\b(?:code (?:de l |de la |du )?(?:url|callback)|callback_url_code)\b/u';
            $credentialPattern = '/\b(?:x-secret|cle secrete|cle d acces)\b/u';
            if (preg_match('/\bauthentifi\w*/u', $clause, $auth, PREG_OFFSET_CAPTURE) === 1) {
                $prefix = substr($clause, 0, $auth[0][1]);
                preg_match_all($routingPattern, $prefix, $routing, PREG_OFFSET_CAPTURE);
                preg_match_all($credentialPattern, $prefix, $credentials, PREG_OFFSET_CAPTURE);
                $lastRoute = $routing[0] === [] ? -1 : end($routing[0])[1];
                $lastCredential = $credentials[0] === [] ? -1 : end($credentials[0])[1];
                $subject = $lastRoute > $lastCredential ? 'routing' : ($lastCredential >= 0 ? 'credential' : $referent);
                if ($subject === 'routing' && preg_match('/\b(?:ne|n)\b.{0,70}\bpas\b/u', $prefix) !== 1) {
                    return ['protocol_contradiction', 'Le code callback est un identifiant de routage ; seule la cle secrete X-Secret authentifie les appels.'];
                }
            }
            if (preg_match($routingPattern, $clause) === 1) {
                $referent = 'routing';
            } elseif (preg_match($credentialPattern, $clause) === 1) {
                $referent = 'credential';
            }
            if (($issue = $this->credentialSentenceIssue($sentence)) !== null) {
                return $issue;
            }
        }

        return null;
    }

    private function credentialSentenceIssue(string $text): ?array
    {
        $flat = $this->flat($text);
        // Callback routing codes and API route codes are not authentication credentials.
        if (preg_match('/\b(?:ne|n)\b.{0,30}\bpas\b/u', $flat) === 1) {
            return null;
        }
        if (str_contains($this->context['flat'], 'echo dynamique')
            && preg_match('/\bresponsecode\b.{0,50}\b(?:valeur|fixe|toujours)\b.{0,15}\b200\b/u', $flat) === 1
            && preg_match('/\b(?:exemple|recu|recue|dynamique|payload)\b/u', $flat) !== 1) {
            return ['protocol_contradiction', 'Accuse de reception : responseCode reprend le code recu, jamais une valeur 200 imposee.'];
        }
        if ((preg_match('/\b(?:fichier|export)\b.{0,160}\b(?:apres|depuis|a partir de|a compter de) (?:sa |la |leur |la fin de la )?(?:generation|creation|telechargement)\b/u', $flat) === 1
            || preg_match('/\b(?:fichier|export)\b.{0,180}\bdemande\b.{0,30}\bcelle (?:de |du )telechargement\b/u', $flat) === 1)
            && preg_match('/\bapres la demande\b/u', $this->context['flat']) === 1) {
            return ['protocol_contradiction', 'La rétention commence après la demande d’export, pas après sa génération ou son téléchargement.'];
        }
        if (preg_match('/\bcode\b.{0,100}\b(?:callback|cette url)\b.{0,120}\bauthentifi\w*/u', $flat) === 1
            || preg_match('/\bcode url\b.{0,60}\b(?:secret|renouvellement)\b.{0,160}\b(?:creation|cree|creation de|genere)\b.{0,100}\bcallback\b/u', $flat) === 1
            || preg_match('/\bcode url\b.{0,60}\b(?:secret|renouvellement)\b.{0,220}\bapres\b.{0,100}\bcallback\b/u', $flat) === 1
            || preg_match('/\bcode\b.{0,100}\b(?:genere|recupere)\b.{0,100}\bcallback\b.{0,80}\bauthentifi\w*/u', $flat) === 1) {
            return ['protocol_contradiction', 'Le code callback identifie une URL ; l authentification utilise la cle secrete X-Secret.'];
        }

        return null;
    }

    private function credentialDurationIssue(string $text): ?array
    {
        $credential = '/\b(?:cle|cles|secret\w*|token\w*|jeton\w*)\b/u';
        $validity = '/\b(?:valid\w*|expir\w*|duree|vie|renouvel\w*)\b/u';
        $flat = $this->flat($text);

        if (preg_match($credential, $flat) !== 1 || preg_match($validity, $flat) !== 1) {
            return null;
        }

        // Check the duration against credential evidence, rather than any number in the corpus.
        $evidence = '';
        foreach ($this->context['chunks'] as $chunk) {
            if (preg_match($credential, $this->flat($chunk['title'])) === 1) {
                $evidence .= "\n".$chunk['raw'];

                continue;
            }
            foreach (preg_split('/\R|(?<=[.!?])\s+/u', $chunk['raw']) ?: [] as $sentence) {
                $sentenceFlat = $this->flat($sentence);
                if (preg_match($credential, $sentenceFlat) === 1 && preg_match($validity, $sentenceFlat) === 1) {
                    $evidence .= "\n".$sentence;
                }
            }
        }

        $seconds = ['s' => 1, 'min' => 60, 'h' => 3600, 'j' => 86400];
        $documentedPairs = $this->numberFacts($evidence)['pairs'];
        // JSON expires_in is a duration even though its unit is implicit.
        preg_match_all('/["\']?expires_in["\']?\s*:\s*(\d+)/u', $evidence, $expires);
        foreach ($expires[1] as $value) {
            $documentedPairs[$value.' s'] = true;
        }
        foreach (array_keys($this->numberFacts($text, claim: true)['pairs']) as $pair) {
            [$value, $unit] = explode(' ', $pair, 2);
            if (! isset($seconds[$unit])) {
                continue;
            }
            $supported = false;
            foreach (array_keys($documentedPairs) as $documented) {
                [$otherValue, $otherUnit] = explode(' ', $documented, 2);
                if (isset($seconds[$otherUnit]) && abs((float) $value * $seconds[$unit] - (float) $otherValue * $seconds[$otherUnit]) < 0.001) {
                    $supported = true;
                    break;
                }
            }
            if (! $supported) {
                return ['misattributed_detail', $pair.' : aucune duree correspondante documentee pour la cle ou le token'];
            }
        }

        return null;
    }

    private function numberIssue(string $text): ?array
    {
        preg_match_all('/\b\d{1,3}(?:\.\d{1,3}){3}\b/u', $text, $addresses);

        foreach ($addresses[0] as $address) {
            if (! str_contains($this->context['raw'], $address)) {
                return ['fabricated_value', $address];
            }
        }

        $text = (string) preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/u', ' ', $text);
        $facts = $this->numberFacts($text, claim: true);

        foreach (array_keys($facts['pairs']) as $pair) {
            // An amount or a share is checked as a number: "150 XAF" is the documented 150.
            if (preg_match('/ (?:xaf|centime|euro|%)$/u', $pair) === 1) {
                continue;
            }

            if (! isset($this->context['pairs'][$pair]) && ! $this->equivalentDuration($pair)) {
                return ['fabricated_value', $pair];
            }

            if (($misattributed = $this->misattribution($pair, $text)) !== null) {
                return ['misattributed_detail', $misattributed];
            }
        }

        foreach (array_keys($facts['numbers']) as $number) {
            if (! isset($this->context['numbers'][$number])) {
                return ['fabricated_value', (string) $number];
            }
        }

        return null;
    }

    /**
     * A figure applied to the wrong one of two sibling pages: the reporting webhook retries "5
     * fois, 5 minutes, 15 minutes, 1 heure, 6 heures", and the answer says so of the status
     * webhook. The claim names the family both titles share ("webhook"), the words proper to the
     * page it talks about ("statut") and none of the words proper to the page that documents the
     * figure ("reporting"), and no extract of the page it talks about carries the figure.
     */
    private function misattribution(string $pair, string $text): ?string
    {
        $claimStems = $this->contentStems($text);
        $carriers = array_values(array_unique(array_column(array_filter(
            $this->context['chunks'],
            static fn (array $chunk): bool => isset($chunk['pairs'][$pair]),
        ), 'title')));

        foreach ($carriers as $carrier) {
            $carrierStems = $this->context['title_stems'][$carrier] ?? [];

            foreach ($this->context['title_stems'] as $title => $stems) {
                if (in_array($title, $carriers, true)) {
                    continue;
                }

                $family = array_intersect($stems, $carrierStems);
                $proper = array_diff($stems, $carrierStems);
                $carrierProper = array_diff($carrierStems, $stems);

                if ($family !== [] && $proper !== [] && $carrierProper !== []
                    && array_intersect($family, $claimStems) !== []
                    && array_intersect($proper, $claimStems) !== []
                    && array_intersect($carrierProper, $claimStems) === []) {
                    return $pair.' (documente dans « '.$carrier.' », pas dans « '.$title.' »)';
                }
            }
        }

        return null;
    }

    /**
     * A label quoted as an interface element (« Parametrages », "Soumettre", or a bold label in
     * a navigation step) must be the documentation's own wording.
     *
     * @return array{0: string, 1: string}|null
     */
    private function labelIssue(string $unit, string $text): ?array
    {
        $labels = [];
        preg_match_all('/«\s*([^»\r\n]{1,60}?)\s*»|“([^”\r\n]{1,60})”/u', $unit, $quoted, PREG_SET_ORDER);

        foreach ($quoted as $match) {
            $labels[] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        }

        if (preg_match(self::NAVIGATION_CUES, $this->flat($text)) === 1) {
            preg_match_all('/\*\*([^*\r\n]{1,40})\*\*/u', $unit, $bold);

            // "1. **Configuration obligatoire** : ..." titles the item in the answer's own words;
            // it names no button or menu of the interface.
            $leadTitle = preg_match('/^\s*(?:[-*+]\s+|\d+[.)]\s+)?\*\*([^*\r\n]{1,40})\*\*\s*:/u', $unit, $lead) === 1
                ? $lead[1]
                : null;

            foreach ($bold[1] as $label) {
                if ($label === $leadTitle) {
                    continue;
                }

                if (count(preg_split('/\s+/u', trim($label)) ?: []) <= 4) {
                    $labels[] = $label;
                }
            }
        }

        foreach ($labels as $label) {
            if (count(preg_split('/\s+/u', trim($label)) ?: []) > 6 || str_contains($label, '`')) {
                continue;
            }

            $stems = $this->contentStems($label);

            if ($stems === [] && ! $this->present(trim($label))) {
                // A short label of stop words only ("Suivant", "OK"): its words must still appear.
                if (! str_contains($this->context['flat'], ' '.trim($this->flat($label)).' ')) {
                    return ['fabricated_label', trim($label)];
                }

                continue;
            }

            foreach ($stems as $stem) {
                if (! isset($this->context['stems'][$stem])) {
                    return ['fabricated_label', trim($label)];
                }
            }
        }

        return null;
    }

    /**
     * A field called mandatory that the documentation lists without "Obligatoire", or optional
     * when it is documented as mandatory. Items of a list introduced by "les champs obligatoires
     * sont :" make that claim through their lead-in.
     *
     * @return array{0: string, 1: string}|null
     */
    private function requirementIssue(string $text, ?string $leadIn): ?array
    {
        $flat = $this->flat($text);
        $optional = '/\b(?:optionnel\w*|facultati\w*|optional|(?:n est |ne sont )?pas (?:obligatoire|requis)\w*|non obligatoire\w*)\b/u';
        $mandatory = '/\b(?:obligatoire\w*|requis|requise|required|mandatory|indispensable\w*)\b/u';
        // "si requis", "le cas echeant" state a condition, not a requirement.
        $conditional = '/\b(?:si (?:requis|requise|necessaire|besoin)|le cas echeant|selon le cas)\b/u';
        $leadInFlat = $leadIn !== null ? $this->flat($leadIn) : '';
        $inherited = $leadInFlat !== '' && preg_match($mandatory, $leadInFlat) === 1 && preg_match($optional, $leadInFlat) !== 1;
        $hasModal = preg_match($optional, $flat) === 1 || preg_match($mandatory, $flat) === 1;

        if (! $hasModal && ! $inherited) {
            return null;
        }

        foreach ($this->mentionedFields($text, onlyLeading: ! $hasModal) as $field) {
            $statuses = $this->context['fields'][$field] ?? [];

            if ($statuses === []) {
                continue;
            }

            $claim = $hasModal ? $this->modalityNear($flat, $field, $optional, $mandatory, $conditional) : 'M';

            if ($claim === 'M' && ! array_intersect($statuses, ['M', 'C'])) {
                return ['contradiction', '« '.$field.' » n est pas marque obligatoire dans la documentation'];
            }

            if ($claim === 'O' && ! array_intersect($statuses, ['O', 'U', 'C'])) {
                return ['contradiction', '« '.$field.' » est documente comme obligatoire'];
            }
        }

        return null;
    }

    /**
     * What a sentence says of one field when it qualifies several ("l en-tete obligatoire
     * X-Secret et optionnel X-Callback-MediaType"): the modal word nearest to the field.
     *
     * @return 'M'|'O'|null
     */
    private function modalityNear(string $flat, string $field, string $optional, string $mandatory, string $conditional): ?string
    {
        $position = strpos($flat, ' '.$field.' ');

        if ($position === false) {
            $position = strpos($flat, $field);
        }

        $candidates = [];

        foreach (['O' => $optional, 'M' => $mandatory] as $kind => $pattern) {
            preg_match_all($pattern, $flat, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$word, $offset]) {
                // "pas obligatoire" is found by both patterns: the optional reading wins.
                if ($kind === 'M' && preg_match('/\b(?:pas|non) $/u', substr($flat, max(0, $offset - 4), 4)) === 1) {
                    continue;
                }

                if (preg_match($conditional, substr($flat, max(0, $offset - 4), strlen($word) + 8)) === 1) {
                    continue;
                }

                $candidates[] = [$kind, $position === false ? 0 : abs($offset - $position)];
            }
        }

        // A modal word far from the field qualifies something else ("repondre (optionnel) ...
        // avec un echo du transactionId").
        $candidates = array_filter($candidates, static fn (array $candidate): bool => $candidate[1] <= 60);

        if ($candidates === [] || $position === false) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $candidates[0][0];
    }

    /**
     * Fields named in a unit: backticked identifiers and bare words that are documented field
     * names. With $onlyLeading, only the field a list item starts with (its subject).
     *
     * @return list<string>
     */
    private function mentionedFields(string $text, bool $onlyLeading): array
    {
        $fields = [];
        $body = (string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $text);

        if ($onlyLeading) {
            if (preg_match('/^\W*`?([A-Za-z][\w.\-]*)`?/u', $body, $match) === 1 && isset($this->context['fields'][strtolower($match[1])])) {
                return [strtolower($match[1])];
            }

            return [];
        }

        preg_match_all('/`([A-Za-z][\w.\-]*)`|\b([A-Za-z][A-Za-z0-9]*(?:[_\-][A-Za-z0-9]+)+|[a-z]+[A-Z][A-Za-z0-9]*)\b/u', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = strtolower(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));

            if ($name !== '' && isset($this->context['fields'][$name])) {
                $fields[] = $name;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * "Envoyez un POST sur /{code-url-status}/status" when the documentation says GET.
     *
     * @return array{0: string, 1: string}|null
     */
    private function methodIssue(string $text): ?array
    {
        preg_match_all('/\b(GET|POST|PUT|PATCH|DELETE)\b\s+`?(\/[^\s`"\']+)/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $template = $this->pathTemplate($match[2]);
            $methods = $this->context['endpoints'][$template] ?? [];

            if ($methods !== [] && ! in_array($match[1], $methods, true)) {
                return ['contradiction', $match[1].' '.$match[2].' (documente en '.implode('/', $methods).')'];
            }
        }

        return null;
    }

    /**
     * A strong qualifier (asynchrone, idempotent, gratuit...) the extracts never use for the
     * subject of the claim, or whose opposite they use for it.
     *
     * @return array{0: string, 1: string}|null
     */
    private function qualifierIssue(string $text): ?array
    {
        $flat = $this->flat($text);

        foreach (self::QUALIFIERS as $qualifier => $pattern) {
            if (preg_match($pattern, $flat) !== 1) {
                continue;
            }

            $topic = $this->topicStems($text, $qualifier);
            $onTopic = static fn (array $chunk): bool => $topic === [] || array_intersect_key($chunk['stems'], array_fill_keys($topic, true)) !== [];
            $opposite = self::ANTONYMS[$qualifier] ?? null;

            if ($opposite !== null) {
                foreach ($this->context['chunks'] as $chunk) {
                    if ($onTopic($chunk) && preg_match(self::QUALIFIERS[$opposite], $chunk['flat']) === 1
                        && preg_match($pattern, $chunk['flat']) !== 1) {
                        return ['contradiction', '« '.$qualifier.' » alors que la documentation dit « '.$opposite.' »'];
                    }
                }
            }

            $supported = false;

            foreach ($this->context['chunks'] as $chunk) {
                if ($onTopic($chunk) && preg_match($pattern, $chunk['flat']) === 1) {
                    $supported = true;

                    break;
                }
            }

            if (! $supported) {
                return ['unsupported_qualifier', $qualifier];
            }
        }

        return null;
    }

    /**
     * "Le callback est uniquement en JSON": the extracts must say "only" for that format, and
     * must not offer an alternative ("application/json ou application/xml").
     *
     * @return array{0: string, 1: string}|null
     */
    private function exclusivityIssue(string $text): ?array
    {
        $flat = $this->flat($text);

        $acknowledgement = preg_match('/\b(?:accuse|reponse|repond\w*)\b/u', $flat.' '.$this->context['question_flat']) === 1;
        $mandatoryFormat = ! $acknowledgement && preg_match('/\b(?:json|xml|csv|pdf|html)\s+(?:est\s+)?(?:obligatoire|impose|requis)\b/u', $flat) === 1;
        if (preg_match(self::EXCLUSIVITY, $flat) !== 1 && ! $mandatoryFormat) {
            return null;
        }

        foreach (self::ALTERNATIVE_CLASSES as $class) {
            foreach ($class as $anchor) {
                if (! str_contains($flat, ' '.$anchor.' ')) {
                    continue;
                }

                $sentences = array_filter(
                    $this->contextSentences(),
                    static fn (string $sentence): bool => str_contains($sentence, ' '.$anchor.' '),
                );

                foreach ($sentences as $sentence) {
                    foreach ($class as $alternative) {
                        if ($alternative !== $anchor && str_contains($sentence, ' '.$alternative.' ')
                            && preg_match('/\b(?:ou|or)\b/u', $sentence) === 1) {
                            return ['contradiction', '« uniquement '.$anchor.' » alors que la documentation propose aussi '.$alternative];
                        }
                    }
                }

                foreach ($sentences as $sentence) {
                    if (preg_match(self::EXCLUSIVITY, $sentence) === 1 || preg_match('/\bseul(?:e|s|es)?\b/u', $sentence) === 1) {
                        return null;
                    }
                }

                return ['unsupported_qualifier', 'exclusivite de '.$anchor];
            }
        }

        return null;
    }

    private function webhookSecretIssue(string $text): ?array
    {
        if ((new ConversationPlan)->subject($this->context['question_flat']) !== 'reporting') {
            return null;
        }
        if (preg_match('/\b(?:recuper\w*|extrai\w*|lis\w*)\b.{0,70}\b(?:en[ -]tete|header)\b.{0,30}\bx[ -]secret\b/u', $this->flat($text)) === 1) {
            return ['contradiction', 'La clé du compte est utilisée pour vérifier la signature ; X-Secret n’est pas un en-tête entrant documenté du webhook de reporting.'];
        }

        return null;
    }

    /**
     * The parenthesized asides of a unit, with the space before them.
     *
     * @return list<string>
     */
    private function parentheticals(string $unit): array
    {
        preg_match_all('/\s*\([^()]*\)/u', $unit, $matches);

        return $matches[0];
    }

    /**
     * A payment method the extracts (and the question) never name. Inside an enumeration
     * ("Airtel Money, Moov Money, Orange Money et MTN Mobile Money") the undocumented names are cut
     * out and the documented ones kept; a unit that is about such a name, or nothing but that
     * list, is removed whole.
     *
     * @return array{0: string, 1: string, 2?: string, 3?: string}|null
     */
    private function paymentMethodIssue(string $unit): ?array
    {
        $flat = $this->flat($this->proseText($unit));
        $undocumented = [];
        // The methods the gateway covers by construction (config rag.gateway_payment_contexts)
        // are documented even when this answer's extracts do not happen to name them, and an
        // operator code such as AIRTEL_MONEY names its method too.
        $documented = str_replace('_', ' ', $this->context['flat'].$this->context['question_flat'])
            .$this->flat(implode(' | ', $this->gatewayPaymentMethods()));

        $methods = self::PAYMENT_METHODS;
        if (preg_match('/\b(?:moyens? de paiement|operateurs?|reseaux|wallets?)\b/u', $flat) === 1) {
            preg_match_all('/\b\p{Lu}\p{Ll}+(?:[ -]+\p{Lu}\p{Ll}+)+\b/u', str_replace(['*', '`'], '', $unit), $names);
            foreach ($names[0] as $name) {
                $methods[] = Str::ascii(mb_strtolower($name));
            }
        }
        foreach (array_unique($methods) as $method) {
            $needle = $this->flat($method);

            if (! str_contains($flat, $needle) || str_contains($documented, $needle)) {
                continue;
            }

            // "MTN Mobile Money" already covers its own "Mobile Money"; never re-report a part.
            if (array_filter($undocumented, static fn (string $found): bool => str_contains(' '.$found.' ', ' '.$method.' ')) !== []) {
                continue;
            }

            $undocumented[] = $method;
        }

        if ($undocumented === []) {
            return null;
        }

        $detail = implode(', ', $undocumented);
        $rest = $unit;

        foreach ($undocumented as $method) {
            $name = '(?:\*\*|__)?'.str_replace(['\ ', '\-'], ['\s+', '[\s-]?'], preg_quote($method, '/')).'(?:\*\*|__)?';
            // An element of an enumeration: its separator goes with it.
            $rest = (string) preg_replace(
                ['/\s*,\s*'.$name.'(?![\p{L}\d])/iu', '/(?<![\p{L}\d])'.$name.'\s*,\s*/iu', '/\s+(?:et|ou|and|or)\s+'.$name.'(?![\p{L}\d])/iu', '/(?<![\p{L}\d])'.$name.'\s+(?:et|ou|and|or)\s+/iu'],
                ['', '', '', ''],
                $rest,
                1,
            );
        }

        $rest = (string) preg_replace(['/\(\s*\)/u', '/\(\s*,\s*/u', '/,\s*\)/u', '/\s{2,}/u'], ['', '(', ')', ' '], $rest);
        $restFlat = $this->flat($this->proseText($rest));
        $stillNamed = array_filter($undocumented, fn (string $method): bool => str_contains($restFlat, $this->flat($method)));

        if ($stillNamed !== [] || preg_match('/[a-z]{3,}/', $restFlat) !== 1) {
            return ['undocumented_payment_method', $detail];
        }

        return ['undocumented_payment_method', $detail, $detail, rtrim($rest)];
    }

    /**
     * @return list<string>
     */
    private function gatewayPaymentMethods(): array
    {
        try {
            return array_values(array_map('strval', (array) config('rag.gateway_payment_contexts', [])));
        } catch (\Throwable) {
            return []; // no application container (plain unit test): rely on the extracts alone
        }
    }

    /**
     * @return array{0: string, 1: string, 2?: string, 3?: string}|null
     */
    private function speculationIssue(string $unit, string $text): ?array
    {
        $flat = $this->flat($text);

        if (preg_match(self::HEDGES, $flat, $hedge) !== 1 || preg_match(self::HEDGES, $this->context['flat']) === 1 && str_contains($this->context['flat'], ' '.$hedge[0].' ')) {
            return null;
        }

        // A guess confined to a parenthesis is cut out and the rest of the unit kept.
        if (preg_match_all('/\s*\(([^()]*)\)/u', $unit, $parentheses, PREG_SET_ORDER) >= 1) {
            foreach ($parentheses as $parenthesis) {
                if (preg_match(self::HEDGES, $this->flat($parenthesis[1])) === 1) {
                    $rest = str_replace($parenthesis[0], '', $unit);

                    if (preg_match(self::HEDGES, $this->flat($this->proseText($rest))) !== 1) {
                        return ['speculation', $hedge[0], trim($parenthesis[0]), $rest];
                    }
                }
            }
        }

        return ['speculation', $hedge[0]];
    }

    /**
     * "La documentation ne mentionne pas XML" while an extract documents application/xml.
     *
     * @return array{0: string, 1: string}|null
     */
    private function falseGapIssue(string $text): ?array
    {
        // Punctuation is kept here: the clause ends at the first stop or colon.
        $plain = (string) preg_replace('/[\'’`*_]+|\s+/u', ' ', Str::ascii(mb_strtolower($text)));

        // What the statement says is missing: the words after "ne precise pas", up to the end of
        // the clause ("... que le format XML est supporte"), not what it says is documented
        // ("elle ne mentionne que JSON").
        preg_match_all('/\b(?:ne|n) (?:\S+ ){0,2}?(?:precise|mentionne|documente|couvre|decrit|indique|detaille|specifie|supporte|traite|prevoit)\w* (?:pas|aucun\w*|rien)\b([^.;:!?]{0,120})/u', $plain, $tails);

        foreach ($tails[1] as $tail) {
            foreach (['xml', 'csv', 'pdf', 'json'] as $format) {
                if (preg_match('/\b'.$format.'\b/u', $tail) === 1 && preg_match('/[\s\/]'.$format.'\b/u', $this->context['raw_lower']) === 1) {
                    return ['contradiction', 'la documentation traite pourtant du format '.strtoupper($format)];
                }
            }
        }

        return null;
    }

    /**
     * Markup the reader would not see ("<% ... %>", HTML tags, comments) hides content from the
     * verification: it is removed, never stripped and trusted.
     *
     * @return array{0: string, 1: string}|null
     */
    private function markupIssue(string $unit): ?array
    {
        if (preg_match('/<%|%>|<!--|<\/?(?:script|style|iframe|img|a|span|div|p|br|b|i|u|em|strong|font|object|embed|svg|form|input)\b[^>]*>/iu', $unit) === 1) {
            return ['code_block', 'balisage'];
        }

        return null;
    }

    /**
     * "Montant en centimes": a currency the extracts never mention.
     *
     * @return array{0: string, 1: string}|null
     */
    private function currencyIssue(string $text): ?array
    {
        preg_match_all('/\b(centimes?|euros?|dollars?|usd|eur|cfa)\b/u', $this->flat($text), $matches);

        foreach (array_unique($matches[1]) as $currency) {
            if (! $this->present($currency) && ! $this->present(rtrim($currency, 's'))) {
                return ['fabricated_value', $currency];
            }
        }

        return null;
    }

    /**
     * An error code next to the wrong constant ("1002 INVALID_MERCHANT_IP_ADDRESS", or that
     * constant "renvoyee avec un HTTP 403"): in a sentence naming both, the documentation's table
     * row of the constant must carry one of the sentence's codes.
     *
     * @return array{0: string, 1: string}|null
     */
    private function pairingIssue(string $text): ?array
    {
        if ($this->context['table_rows'] === []) {
            return null;
        }

        foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [] as $sentence) {
            preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b/u', $sentence, $constants);
            preg_match_all('/(?<![\w.,])\d{3,5}(?![\w,]|[.,]\d)/u', $sentence, $codes);

            if ($constants[0] === [] || $codes[0] === []) {
                continue;
            }

            foreach (array_unique($constants[0]) as $constant) {
                $rows = array_filter($this->context['table_rows'], static fn (string $row): bool => preg_match('/(?<![A-Z0-9_])'.preg_quote($constant, '/').'(?![A-Z0-9_])/u', $row) === 1);

                if ($rows === []) {
                    continue;
                }

                $matched = false;

                foreach ($rows as $row) {
                    foreach ($codes[0] as $code) {
                        if (preg_match('/(?<![\d.])'.$code.'(?![\d.])/u', $row) === 1) {
                            $matched = true;
                        }
                    }
                }

                if (! $matched) {
                    return ['contradiction', $constant.' n est pas documente avec le code '.implode(' / ', array_unique($codes[0]))];
                }
            }
        }

        return null;
    }

    /**
     * A sentence the documentation shows as wrong ("Exemple incorrect", "Cette affirmation est
     * fausse") restated as a fact.
     *
     * @return array{0: string, 1: string}|null
     */
    private function disavowedIssue(string $text): ?array
    {
        foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [] as $sentence) {
            $stems = array_fill_keys($this->contentStems($sentence), true);

            if (count($stems) < 3) {
                continue;
            }

            foreach ($this->context['sentences'] as $documented) {
                if ($documented['disavowed'] && count(array_intersect_key($stems, $documented['stems'])) >= max(3, (int) ceil(count($stems) * 0.7))) {
                    return ['contradiction', 'reprend un contre-exemple de la documentation'];
                }
            }
        }

        return null;
    }

    /**
     * A claim that reverses the passage it restates: it waives what the documentation requires
     * ("votre serveur n a pas besoin de repondre 200" / "doit imperativement repondre"), requires
     * what it calls optional, or grants what it excludes ("les URL en localhost sont acceptees" /
     * "ne seront pas valides"). A negation alone is not compared: "n est pas le meme", "si le
     * callback n est pas recu" negate nothing the documentation states.
     *
     * @return array{0: string, 1: string}|null
     */
    private function polarityIssue(string $unit, string $text): ?array
    {
        foreach (preg_split('/(?<=[.!?;:])\s+/u', $text) ?: [] as $sentence) {
            $flat = $this->flat($sentence);
            $stems = array_fill_keys($this->contentStems($sentence), true);
            $negative = preg_match(self::NEGATION, $flat) === 1;

            if (count($stems) < 3) {
                continue;
            }

            if (preg_match(self::DISPENSATION, $flat) === 1) {
                foreach ($this->context['sentences'] as $documented) {
                    $shared = count(array_intersect_key($stems, $documented['stems']));

                    if ($shared < 3 || $shared < count($stems) * 0.5 || $documented['negative']
                        || preg_match(self::DISPENSATION, $documented['flat']) === 1
                        || preg_match(self::OBLIGATION, $documented['flat']) !== 1) {
                        continue;
                    }

                    // "(optionnel mais recommande)" on a step the documentation requires: the
                    // parenthesis is wrong, the step itself is right.
                    foreach ($this->parentheticals($unit) as $parenthetical) {
                        if (preg_match(self::DISPENSATION, $this->flat($parenthetical)) === 1) {
                            return ['contradiction', 'la documentation l exige', trim($parenthetical), str_replace($parenthetical, '', $unit)];
                        }
                    }

                    return ['contradiction', 'la documentation l exige'];
                }
            }

            // "Les URL en localhost sont acceptees": a rare subject the documentation only
            // mentions in a negative sentence, now granted.
            if (! $negative && preg_match(self::PERMISSION, $flat) === 1) {
                foreach ($this->context['sentences'] as $documented) {
                    if (! $documented['negative']) {
                        continue;
                    }

                    $rare = array_filter(
                        array_keys(array_intersect_key($stems, $documented['stems'])),
                        fn (string $stem): bool => ! in_array($stem, self::GENERIC_STEMS, true) && ($this->context['df'][$stem] ?? 0) === 1,
                    );

                    $grantedElsewhere = array_filter($this->context['sentences'], static fn (array $other): bool => ! $other['negative']
                        && array_intersect_key(array_fill_keys($rare, true), $other['stems']) !== []);

                    if ($rare !== [] && $grantedElsewhere === []) {
                        return ['contradiction', 'la documentation exclut '.implode(', ', $rare)];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function groundingIssue(string $text): ?array
    {
        $stems = $this->contentStems($text);

        if (count($stems) < self::GROUNDING_SHORT_MIN_WORDS) {
            return null;
        }

        $known = array_filter($stems, fn (string $stem): bool => isset($this->context['stems'][$stem]));
        $minimum = count($stems) >= self::GROUNDING_MIN_WORDS ? self::GROUNDING_MIN_COVERAGE : self::GROUNDING_SHORT_MIN_COVERAGE;

        if (count($known) / count($stems) >= $minimum) {
            return null;
        }

        return ['ungrounded', sprintf('%d mots sur %d absents des extraits', count($stems) - count($known), count($stems))];
    }

    // ---------------------------------------------------------------------------------------
    // Examples (fenced blocks)
    // ---------------------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string}|null
     */
    private function exampleIssue(string $block): ?array
    {
        if (preg_match('/^\s*(?:```|~~~)([A-Za-z0-9_+-]*)[ \t]*\R?([\s\S]*?)(?:```|~~~)?\s*$/u', $block, $fence) !== 1) {
            return ['code_block', 'bloc illisible'];
        }

        $language = $fence[1];
        $body = (string) preg_replace('/^[ \t]+/mu', '', $fence[2]);

        if (str_contains($body, '\\"') && $this->responseGuard->isDocumentedApiExample($language, str_replace('\\"', '"', $body))) {
            $body = str_replace('\\"', '"', $body);
        }

        $urlOnly = preg_match('/\A\s*(?:(?:GET|POST|PUT|PATCH|DELETE)\s+)?https?:\/\/\S+\s*\z/u', $body) === 1;

        if (! $urlOnly && ! $this->responseGuard->isDocumentedApiExample($language, $body)) {
            return ['code_block', $language !== '' ? $language : 'bloc'];
        }

        $command = (string) preg_replace('/\\\\[ \t]*\R[ \t]*/u', ' ', $body);
        preg_match_all('/https?:\/\/[^\s"\'`]+/u', $command, $urls);
        $method = preg_match('/(?:-X|--request)\s+([A-Z]+)/u', $command, $explicit) === 1
            ? $explicit[1]
            : (preg_match('/^\s*(GET|POST|PUT|PATCH|DELETE)\b/u', $command, $leading) === 1 ? $leading[1]
                : (preg_match('/\s(?:-d|-F|--data(?:-raw|-binary|-ascii|-urlencode)?|--form|--json)\s/u', $command) === 1 ? 'POST' : 'GET'));

        foreach ($urls[0] as $url) {
            $url = rtrim($url, '"\'.,;)');
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $root = $this->rootDomain($host);

            if (! in_array($root, $this->officialRootDomains(), true) && ! str_contains(Str::ascii(mb_strtolower($this->context['raw'])), $root)) {
                return ['invalid_example', 'domaine '.$host.' non documente'];
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

            if (trim($path, '/') !== '' && ! $this->pathDocumented($path)) {
                return ['invalid_example', 'chemin '.$path.' non documente'];
            }

            $methods = $this->context['endpoints'][$this->pathTemplate($path)] ?? [];

            if (preg_match('/\bcurl\b|^\s*(?:GET|POST|PUT|PATCH|DELETE)\b/u', $command) === 1 && $methods !== [] && ! in_array($method, $methods, true)) {
                return ['invalid_example', $method.' au lieu de '.implode('/', $methods).' pour '.$path];
            }

            $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');

            foreach (array_filter(explode('&', $query)) as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

                if (! $this->present($name)) {
                    return ['invalid_example', 'parametre '.$name.' non documente'];
                }

                if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/u', $value) === 1 && ! $this->present($value) && ! $this->isPlaceholder($value)) {
                    return ['invalid_example', 'valeur '.$value.' non documentee'];
                }
            }
        }

        preg_match_all('/(?:--header|-H)\s+(?:\'([^\'\r\n]*)\'|"([^"\r\n]*)")/u', $command, $headerArguments, PREG_SET_ORDER);
        $headers = array_map(static fn (array $match): string => ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''), $headerArguments);

        if (preg_match('/\bcurl\b/u', $command) !== 1) {
            preg_match_all('/^([A-Za-z][A-Za-z0-9-]*)[ \t]*:[ \t]*(\S.*)$/mu', $body, $headerLines, PREG_SET_ORDER);

            foreach ($headerLines as $line) {
                if (! in_array(strtolower($line[1]), ['http', 'https', 'get', 'post', 'put', 'patch', 'delete'], true)) {
                    $headers[] = $line[1].': '.$line[2];
                }
            }
        }

        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', array_pad(explode(':', $header, 2), 2, ''));

            if ($name === '' || ! $this->present($name)) {
                return ['invalid_example', 'en-tete '.$name.' non documente'];
            }

            if ($value !== '' && ! $this->isPlaceholder($value) && preg_match('/^(?:application|text)\/[a-z0-9.+-]+$/iu', $value) !== 1
                && ! str_contains($this->context['flat'], ' '.trim($this->flat($value)).' ')) {
                return ['invalid_example', 'valeur d en-tete '.$value.' non documentee'];
            }
        }

        $payloads = [];
        preg_match_all('/(?:--data(?:-raw|-ascii|-binary)?|-d)\s+\'([\s\S]*?)\'(?=\s|$)/u', $command, $data);

        foreach ($data[1] as $payload) {
            if (preg_match('/\A\s*[\{\[]/u', $payload) === 1) {
                $payloads[] = $payload;
            }
        }

        if (preg_match('/\bcurl\b/u', $command) !== 1 && preg_match('/\A\s*[\{\[]/u', $body) === 1) {
            $payloads[] = $body;
        }

        foreach ($payloads as $payload) {
            if (($issue = $this->jsonIssue($payload)) !== null) {
                return ['invalid_example', $issue];
            }
        }

        return null;
    }

    private function jsonIssue(string $body): ?string
    {
        $body = trim($body);

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'JSON invalide';
        }

        if (! is_array($decoded)) {
            return 'JSON invalide';
        }

        $issue = null;
        array_walk_recursive($decoded, function ($value, $key) use (&$issue): void {
            if ($issue !== null) {
                return;
            }

            if (is_string($key) && ! $this->present($key)) {
                $issue = 'champ '.$key.' non documente';

                return;
            }

            if (is_string($value)) {
                $value = trim($value);

                // Descriptive text and placeholders ("msisdn_partenaire", "ACC_partenaire_code")
                // are the reader's to replace, and so is an illustrative phone number.
                if ($value === '' || $this->isPlaceholder($value) || preg_match('/\s/u', $value) === 1
                    || (preg_match('/[a-z]/u', $value) === 1 && preg_match('/\d/u', $value) !== 1 && ! str_contains($value, '/'))
                    || (is_string($key) && preg_match('/msisdn|phone|telephone|numero|account_number/iu', $key) === 1 && preg_match('/^\+?\d{6,15}$/u', $value) === 1)) {
                    return;
                }

                if (! $this->present($value) && ! str_contains($this->context['flat'], ' '.trim($this->flat($value)).' ')) {
                    $issue = 'valeur '.$value.' non documentee';
                }

                return;
            }

            if (is_int($value) || is_float($value)) {
                if (! isset($this->context['numbers'][$this->normalizeNumber((string) $value)])) {
                    $issue = 'valeur '.$value.' non documentee';
                }
            }
        });

        return $issue;
    }

    // "// ..." annotations of a JSON example, removed wherever they sit outside a string (even
    // when they quote alternatives: "service": "WEB", // ou "RESTLINK").
    private function withoutJsonComments(string $body): string
    {
        $result = '';
        $inString = false;
        $escaped = false;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($inString) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '/' && ($body[$i + 1] ?? '') === '/') {
                while ($i < $length && $body[$i] !== "\n") {
                    $i++;
                }
                $result .= "\n";

                continue;
            }
            $result .= $char;
        }

        return $result;
    }

    // ---------------------------------------------------------------------------------------
    // Atoms, numbers and words
    // ---------------------------------------------------------------------------------------

    /**
     * The checkable specifics of a prose text: code spans, headers, constants, enum values,
     * camelCase and snake_case identifiers, and paths.
     *
     * @return list<string>
     */
    private function atoms(string $text): array
    {
        $atoms = [];
        $text = (string) preg_replace('/https?:\/\/\S+|\bwww\.\S+/u', ' ', $text);

        preg_match_all('/`([^`\r\n]+)`/u', $text, $spans);

        foreach ($spans[1] as $span) {
            $span = trim($span);

            if (preg_match('/^[A-Za-z0-9_.\-{}\/:]+$/u', $span) === 1) {
                $atoms[] = rtrim($span, ':.');
            } else {
                // An expression (`horodatage + "." + corps`): only its API-like identifiers count.
                preg_match_all('/\b(?:X-[A-Za-z][A-Za-z0-9-]*|[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+|[a-z]+(?:[A-Z][a-z0-9]+)+)\b/u', $span, $inner);
                array_push($atoms, ...$inner[0]);
            }
        }

        $prose = (string) preg_replace('/`[^`\r\n]+`/u', ' ', $text);
        preg_match_all(
            '/\bX-[A-Za-z][A-Za-z0-9-]*\b'
            .'|\b[A-Z][A-Z0-9]*(?:[_-][A-Z0-9]+)+\b'
            .'|\b[A-Z]{2,}[0-9]+[A-Z0-9]*\b'
            .'|\b[A-Z]{4,}\b'
            .'|\b[a-z]+(?:[A-Z][a-z0-9]+)+\b'
            .'|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b'
            .'|(?<![\w.:\/])\/(?:\{\{?[\w-]+\}?\}|[A-Za-z][\w.-]*)(?:\/(?:\{\{?[\w-]+\}?\}|[\w.-]+))*/u',
            $prose,
            $matches,
        );

        foreach ($matches[0] as $atom) {
            if (in_array(strtoupper($atom), self::COMMON_ACRONYMS, true) && preg_match('/[_\d-]/u', $atom) !== 1) {
                continue;
            }

            if (preg_match('/^[A-Z]{4,}$/u', $atom) === 1 && in_array($atom, self::COMMON_ACRONYMS, true)) {
                continue;
            }

            $atoms[] = rtrim($atom, '.:,');
        }

        return array_values(array_unique(array_filter($atoms, static fn (string $atom): bool => mb_strlen($atom) >= 2)));
    }

    private function isPlaceholderHost(string $reference): bool
    {
        $host = (string) (parse_url(str_contains($reference, '://') ? $reference : 'https://'.$reference, PHP_URL_HOST) ?: '');

        return preg_match('/(?:^|[.-])(?:votre|your|mon|my|example|exemple|domaine|domain|monsite|mondomaine)(?:[.-]|$)|votre-domaine|mon-domaine|example\.(?:com|org)/u', $host) === 1;
    }

    private function isPlaceholder(string $value): bool
    {
        if (preg_match('/^https?:\/\//iu', $value) === 1 && $this->isPlaceholderHost(Str::ascii(mb_strtolower($value)))) {
            return true;
        }

        if (preg_match('/^[\w.-]+\.(?:csv|json|txt|xml|pdf|log|php|js|py|zip)$/iu', $value) === 1) {
            return true; // a local file name ("report.csv")
        }

        return preg_match('/\{\{[^}]*\}\}|^\{[^}]*\}$|^<[^>]*>$|votre|your|xxx|\.\.\.|…|^(?:mon|my|ton)_|_ici$|exemple|example|placeholder/iu', $value) === 1;
    }

    // Whole-token presence in the extracts or in the question, "-" and "_" interchangeable.
    private function present(string $atom): bool
    {
        $needle = trim($this->flat($atom));

        if ($needle === '') {
            return true;
        }

        foreach (array_unique([$needle, str_replace('_', ' ', $needle), str_replace('-', '_', $needle), str_replace('_', '-', $needle)]) as $candidate) {
            $pattern = '/(?<![a-z0-9_])'.preg_quote($candidate, '/').'(?![a-z0-9_])/u';

            if (preg_match($pattern, $this->context['flat']) === 1 || preg_match($pattern, $this->context['question_flat']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function pathDocumented(string $path): bool
    {
        $template = $this->pathTemplate($path);

        if ($template === '' || isset($this->context['endpoints'][$template])) {
            return true;
        }

        // A partial path ("/rest", "/renew-secret") is documented when its literal segments are
        // segments of a documented endpoint, in the same order.
        $segments = array_values(array_filter(explode('/', $template), static fn (string $segment): bool => $segment !== '*'));

        if ($segments === []) {
            return true;
        }

        foreach (array_keys($this->context['endpoints']) as $documented) {
            $documentedSegments = array_values(array_filter(explode('/', (string) $documented), static fn (string $segment): bool => $segment !== '*'));
            $position = 0;

            foreach ($documentedSegments as $segment) {
                if ($position < count($segments) && str_replace('_', '-', $segment) === str_replace('_', '-', $segments[$position])) {
                    $position++;
                }
            }

            if ($position === count($segments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path with its variable parts made uniform: "/v2/{{code_url_report}}/reports/0f3c9c02-...
     * /download" and "/{code-url-report}/reports/{jobId}/download" both become
     * "* /reports/ * /download" (without the spaces).
     */
    private function pathTemplate(string $path): string
    {
        $path = strtolower(explode('?', $path)[0]);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

        if (($segments[0] ?? null) === 'v2') {
            array_shift($segments);
        }

        $segments = array_map(static fn (string $segment): string => preg_match(
            '/^\{\{?[^}]*\}?\}$|^:[\w-]+$|^[0-9a-f]{8}-[0-9a-f-]{27,}$|^(?=.*\d)[a-z0-9_-]{8,}$|^\d+$/u',
            $segment,
        ) === 1 ? '*' : $segment, $segments);

        return implode('/', $segments);
    }

    /**
     * Numbers and number+unit pairs. In a claim only numbers of two digits or more, or with a
     * unit, count (not list positions or "etape 2").
     *
     * @return array{numbers: array<string, true>, pairs: array<string, true>}
     */
    private function numberFacts(string $text, bool $claim = false): array
    {
        $flat = Str::ascii(mb_strtolower($text));
        $flat = (string) preg_replace('/^\s*(?:[-*+]\s+)?\d+[.)]\s+/mu', ' ', $flat);
        $flat = (string) preg_replace('/(\d)[ \x{00A0}\x{202F}](?=\d{3}\b)/u', '$1', $flat);
        $pairs = [];
        $numbers = [];

        preg_match_all('/(?<![\w.,\/-])(\d+(?:[.,]\d+)?)\s*'.self::UNIT_WORDS.'(?![a-z])/u', $flat, $pairMatches, PREG_SET_ORDER);

        foreach ($pairMatches as $match) {
            $pairs[$this->normalizeNumber($match[1]).' '.$this->unitKey($match[2])] = true;
        }

        preg_match_all('/(?<![\w.,\/-])\d+(?:[.,]\d+)?(?![\w])/u', $flat, $numberMatches);

        foreach ($numberMatches[0] as $number) {
            if (! $claim || strlen(preg_replace('/\D/', '', $number)) >= 2) {
                $numbers[$this->normalizeNumber($number)] = true;
            }
        }

        return ['numbers' => $numbers, 'pairs' => $pairs];
    }

    private function normalizeNumber(string $number): string
    {
        $number = str_replace(',', '.', $number);

        $normalized = str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : ltrim($number, '0');

        return $normalized !== '' ? $normalized : '0';
    }

    private function unitKey(string $unit): string
    {
        return match (true) {
            in_array($unit, ['fois', 'tentative', 'tentatives', 'essai', 'essais'], true) => 'x',
            in_array($unit, ['jour', 'jours', 'j'], true) => 'j',
            in_array($unit, ['heure', 'heures', 'h'], true) => 'h',
            in_array($unit, ['minute', 'minutes', 'min', 'mn'], true) => 'min',
            in_array($unit, ['seconde', 'secondes', 'sec', 's'], true) => 's',
            in_array($unit, ['an', 'ans', 'annee', 'annees'], true) => 'an',
            in_array($unit, ['caractere', 'caracteres', 'car'], true) => 'car',
            in_array($unit, ['ligne', 'lignes'], true) => 'ligne',
            in_array($unit, ['export', 'exports'], true) => 'export',
            in_array($unit, ['xaf', 'fcfa'], true) => 'xaf',
            in_array($unit, ['centime', 'centimes'], true) => 'centime',
            in_array($unit, ['euro', 'euros'], true) => 'euro',
            default => $unit,
        };
    }

    /**
     * Content words of a text, accents and case folded, function words dropped, cut to a
     * six-letter stem after light French suffix stripping ("notification" / "notifie" meet).
     *
     * @return list<string>
     */
    private function contentStems(string $text): array
    {
        static $stop = null;
        $stop ??= array_fill_keys(self::STOP_WORDS, true);
        $words = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower(strip_tags($text))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(
            static fn (string $word): string => substr(self::lightStem($word), 0, 6),
            array_filter($words, static fn (string $word): bool => strlen($word) >= 4 && ! isset($stop[$word]) && ! ctype_digit($word)),
        )));
    }

    private static function lightStem(string $word): string
    {
        foreach (['ements', 'ement', 'ations', 'ation', 'ees', 'ee', 'es', 'er', 'ez', 'ent', 'ons', 'e', 's'] as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) - strlen($suffix) >= 3) {
                $word = substr($word, 0, -strlen($suffix));

                break;
            }
        }

        // Verbs in -oyer/-ayer/-uyer alternate y and i: "envoyees" and "envoie" share one stem.
        return str_ends_with($word, 'y') ? substr($word, 0, -1).'i' : $word;
    }

    /**
     * The words of a claim that say what it is about, as opposed to the qualifier itself and
     * the words every extract shares.
     *
     * @return list<string>
     */
    private function topicStems(string $text, string $qualifier): array
    {
        $exclude = array_merge(self::GENERIC_STEMS, $this->contentStems($qualifier), ['synchr', 'asynch']);
        $count = max(1, count($this->context['chunks']));
        $candidates = array_values(array_filter(
            $this->contentStems($text),
            fn (string $stem): bool => ! in_array($stem, $exclude, true)
                && ($this->context['df'][$stem] ?? 0) >= 1
                && ($this->context['df'][$stem] ?? 0) <= max(1, (int) floor($count / 2)),
        ));

        if ($candidates === []) {
            return [];
        }

        // The rarest of them name the subject ("remboursement"), not the shared vocabulary
        // ("callback") that would let a sentence about payments vouch for it.
        $rarest = min(array_map(fn (string $stem): int => $this->context['df'][$stem], $candidates));

        return array_values(array_filter($candidates, fn (string $stem): bool => $this->context['df'][$stem] === $rarest));
    }

    /**
     * @return list<string>
     */
    private function contextSentences(): array
    {
        return array_column($this->context['sentences'], 'flat');
    }

    private function isGapStatement(string $flat): bool
    {
        return preg_match('/\b(?:documentation|doc|extraits?|sources?|pages?)\b/u', $flat) === 1
            && preg_match('/\bne? (?:(?:le|la|les|l|en) )?(?:precise|mentionne|documente|couvre|decrit|indique|detaille|traite|contient|fournit|donne|aborde|specifie)\w* (?:pas|aucun|rien)|\bn (?:est|sont) pas (?:documente|precise|mentionne|couvert|decrit|indique|detaille)|\baucune? (?:information|mention|precision|indication|detail)\b/u', $flat) === 1
            || preg_match('/\b(?:je ne (?:traite|reponds|couvre)|je traite uniquement|hors (?:du )?perimetre)\b/u', $flat) === 1;
    }

    private function isListItem(string $unit): bool
    {
        return preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/u', $unit) === 1;
    }

    // Lowercase ASCII, punctuation and markup folded to single spaces, padded with one space so
    // that a word can be matched whole with ' word '.
    private function flat(string $text): string
    {
        $text = Str::ascii(mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return ' '.trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^a-z0-9_\-]+/u', ' ', $text))).' ';
    }

    // The unit without citation markers, list markers or emphasis.
    private function proseText(string $unit): string
    {
        return trim((string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/mu', '', $this->withoutMarkers($unit)));
    }

    private function withoutMarkers(string $text): string
    {
        return (string) preg_replace(self::CITATION, ' ', $text);
    }

    /**
     * @return list<int>
     */
    private function markerIds(string $text): array
    {
        preg_match_all(self::CITATION, $text, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    // "3600 secondes, soit 1 heure": a duration the extracts state in another unit.
    private function equivalentDuration(string $pair): bool
    {
        $seconds = ['s' => 1, 'min' => 60, 'h' => 3600, 'j' => 86400];
        [$value, $unit] = array_pad(explode(' ', $pair, 2), 2, '');

        if (! isset($seconds[$unit])) {
            return false;
        }

        $target = (float) $value * $seconds[$unit];

        foreach (array_keys($this->context['pairs']) as $documented) {
            [$documentedValue, $documentedUnit] = array_pad(explode(' ', (string) $documented, 2), 2, '');

            if (isset($seconds[$documentedUnit]) && abs((float) $documentedValue * $seconds[$documentedUnit] - $target) < 0.001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function citedIds(string $answer): array
    {
        return array_values(array_filter($this->markerIds($answer), fn (int $id): bool => isset($this->context['ids'][$id])));
    }

    /**
     * The chunks an uncited answer relies on: those sharing most content words with it.
     *
     * @return list<int>
     */
    private function supportingIds(string $answer, Collection $chunks): array
    {
        $answerStems = array_fill_keys($this->contentStems($this->withoutMarkers($answer)), true);
        $scores = [];

        foreach ($chunks as $chunk) {
            $stems = array_fill_keys($this->contentStems((string) data_get($chunk, 'contenu', '')), true);
            $scores[(int) data_get($chunk, 'chunk_id')] = count(array_intersect_key($answerStems, $stems));
        }

        arsort($scores);
        $best = (int) (reset($scores) ?: 0);

        if ($best < 3) {
            return [];
        }

        return array_slice(array_keys(array_filter($scores, static fn (int $score): bool => $score >= max(3, (int) ceil($best * 0.4)))), 0, 3);
    }

    // ---------------------------------------------------------------------------------------
    // Structure: blocks, units and reassembly
    // ---------------------------------------------------------------------------------------

    /** Repair Markdown delimiters without changing an example's content. */
    private function normalizeCodeFences(string $answer): string
    {
        $lines = preg_split('/\R/u', $answer) ?: [];
        $opening = null;
        $result = [];
        foreach ($lines as $line) {
            if ($opening === null) {
                if (preg_match('/^([ \t]*)(`{3,}|~{3,})([A-Za-z0-9_+-]*)[ \t]*$/u', $line, $match) === 1) {
                    $opening = ['indent' => $match[1], 'marker' => $match[2]];
                }
                $result[] = $line;

                continue;
            }
            $marker = preg_quote($opening['marker'][0], '/');
            $length = strlen($opening['marker']);
            if (preg_match('/^[ \t]*('.$marker.'{'.$length.',})[ \t]*(.*)$/u', $line, $match) === 1
                && (trim($match[2]) === '' || preg_match('/^(?:\[SOURCE:\d+\]|\[\d+\])/u', trim($match[2])) === 1)) {
                // A closing fence must match its list indentation and contain no citation.
                // Otherwise Markdown treats it as a new opening block and swallows the prose.
                $result[] = $opening['indent'].$match[1];
                if (trim($match[2]) !== '') {
                    $result[] = '';
                    $result[] = $opening['indent'].trim($match[2]);
                }
                $opening = null;
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Blank-line separated blocks; a fenced code block is always a block of its own.
     *
     * @return list<array{kind: 'code'|'text', text: string}>
     */
    private function answerBlocks(string $answer): array
    {
        $blocks = [];
        $current = [];
        $inFence = false;
        $flush = static function (string $kind) use (&$blocks, &$current): void {
            $text = trim(implode("\n", $current), "\n");

            if (trim($text) !== '') {
                $blocks[] = ['kind' => $kind, 'text' => $text];
            }

            $current = [];
        };

        foreach (preg_split('/\R/u', $answer) ?: [] as $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line) === 1) {
                if (! $inFence) {
                    $flush('text');
                    $inFence = true;
                    $current[] = $line;
                } else {
                    $current[] = $line;
                    $flush('code');
                    $inFence = false;
                }

                continue;
            }

            if ($inFence) {
                $current[] = $line;

                continue;
            }

            if (trim($line) === '') {
                $flush('text');

                continue;
            }

            $current[] = $line;
        }

        $flush($inFence ? 'code' : 'text');

        return $blocks;
    }

    /**
     * Each list item (with its continuation lines) is a unit; the lines before the first item
     * are one unit.
     *
     * @return list<string>
     */
    private function blockUnits(string $text): array
    {
        $units = [];
        $current = '';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/u', $line) === 1 && $current !== '') {
                $units[] = $current;
                $current = $line;

                continue;
            }

            $current = $current === '' ? $line : $current."\n".$line;
        }

        if (trim($current) !== '') {
            $units[] = $current;
        }

        return $units;
    }

    /**
     * The answer rebuilt from what was kept, in its own order and layout. A lead-in left with
     * nothing to introduce is dropped, and so is the label of a removed example.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function assemble(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if ($block['kind'] === 'code') {
                if ($block['dropped']) {
                    $last = array_key_last($parts);

                    if ($last !== null && $parts[$last]['kind'] === 'label') {
                        array_pop($parts);
                    } elseif ($last !== null && $parts[$last]['kind'] === 'text') {
                        // The sentence that announced the removed example now ends the thought.
                        $parts[$last]['text'] = (string) preg_replace('/[ \t]*:[ \t]*((?:\[SOURCE:\d+\][ \t]*)*)$/u', '. $1', rtrim($parts[$last]['text']));
                    }

                    continue;
                }

                $parts[] = ['kind' => 'code', 'text' => $block['text']];

                continue;
            }

            $units = $this->withoutEmptiedLabels($block['units']);

            if ($units === []) {
                continue;
            }

            $onlyStructural = array_filter($units, static fn (array $unit): bool => ! $unit['structural']) === [];
            $text = implode("\n", array_column($units, 'text'));
            $parts[] = [
                'kind' => $onlyStructural ? 'label' : 'text',
                'text' => $text,
                'announces' => $onlyStructural && preg_match('/:\s*(?:\*\*)?\s*$/u', $text) === 1,
            ];
        }

        $kept = [];

        foreach ($parts as $i => $part) {
            if ($part['kind'] === 'label' && ! empty($part['announces'])) {
                $next = $parts[$i + 1] ?? null;

                if ($next === null || ($next['kind'] === 'label' && ! empty($next['announces']))) {
                    continue;
                }
            }

            $kept[] = $part['text'];
        }

        return $this->renumberOrderedLists(trim(implode("\n\n", $kept)));
    }

    /**
     * A step label whose sub-items were all removed is followed directly by the next label: it
     * announces nothing and is dropped.
     *
     * @param  list<array{text: string, structural: bool}>  $units
     * @return list<array{text: string, structural: bool}>
     */
    private function withoutEmptiedLabels(array $units): array
    {
        $indent = static fn (string $text): int => strlen($text) - strlen(ltrim($text, " \t"));
        $isLabel = static fn (array $unit): bool => $unit['structural'] && preg_match('/:\s*(?:\*\*)?\s*$/u', $unit['text']) === 1;
        $kept = [];

        foreach ($units as $i => $unit) {
            $next = $units[$i + 1] ?? null;

            if ($isLabel($unit) && ($next === null && $i > 0 || $next !== null && $isLabel($next) && $indent($next['text']) <= $indent($unit['text']))) {
                continue;
            }

            // "2. Vérifiez les URLs :" followed by step 3: a numbered step that introduced
            // sub-items, all removed. A lead-in sentence followed by its own list ("Suivez ces
            // étapes :"), or a step followed by unindented bullets, still has something to announce.
            $isStep = static fn (string $text): bool => preg_match('/^\s*\d+[.)]\s+/u', $text) === 1;

            if ($isLabel($unit) && $isStep($unit['text']) && $next !== null
                && $isStep($next['text']) && $indent($next['text']) <= $indent($unit['text'])) {
                continue;
            }

            $kept[] = $unit;
        }

        return $kept;
    }

    /**
     * A removed code example leaves its citation alone on a line, under the sentence that
     * announced it ("Exemple de champ dans la requete :" then "[SOURCE:600]"). The announcement
     * goes with the example; any other line keeps the citation at its end.
     */
    private function withoutOrphanedCitations(string $answer): string
    {
        $markerOnly = '/^\s*(?:\[SOURCE:\d+\](?:\s*(?:,|et)?\s*)?)+\.?\s*$/u';
        $lines = preg_split('/\R/u', $answer) ?: [];
        $kept = [];
        $inFence = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line) === 1) {
                $inFence = ! $inFence;
            }

            if ($inFence || preg_match($markerOnly, $line) !== 1) {
                $kept[] = $line;

                continue;
            }

            $markers = implode(' ', array_map(static fn (array $m): string => $m[0], $this->allMatches(self::CITATION, $line)));
            $previous = null;

            for ($j = count($kept) - 1; $j >= 0; $j--) {
                if (trim($kept[$j]) !== '') {
                    $previous = $j;

                    break;
                }
            }

            if ($previous === null) {
                continue;
            }

            $announcement = trim((string) preg_replace('/[*_\s]+$/u', '', $kept[$previous]));

            if (str_ends_with($announcement, ':') && ! $this->introducesNextLine($kept[$previous], $lines, $index)) {
                array_splice($kept, $previous);

                continue;
            }

            $kept[$previous] = rtrim($kept[$previous]).' '.$markers;
        }

        return (string) preg_replace('/\n{3,}/u', "\n\n", implode("\n", $kept));
    }

    /**
     * Whether the line after the orphaned citation is a sub-item of the announcing line, which
     * then still has something to announce.
     *
     * @param  list<string>  $lines
     */
    private function introducesNextLine(string $announcing, array $lines, int $index): bool
    {
        $indent = static fn (string $text): int => strlen($text) - strlen(ltrim($text, " \t"));

        for ($k = $index + 1; $k < count($lines); $k++) {
            if (trim($lines[$k]) === '') {
                continue;
            }

            return $this->isListItem($lines[$k]) && $indent($lines[$k]) > $indent($announcing);
        }

        return false;
    }

    /**
     * @return list<array<int, string>>
     */
    private function allMatches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        return $matches;
    }

    // Removing a step leaves gaps ("1. 2. 4."): ordered items are renumbered at each level.
    private function renumberOrderedLists(string $text): string
    {
        $counters = [];
        $lines = preg_split('/\R/u', $text) ?: [];
        $inFence = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*(?:(?:\[SOURCE:\d+\]|\[\d+\])\s*)+\.?\s*$/u', $line) === 1) {
                continue;
            }

            if (preg_match('/^(\s*)(\d+)([.)])(\s+.*)$/u', $line, $match) === 1) {
                $depth = strlen($match[1]);
                $counters = array_filter($counters, static fn (int $level): bool => $level <= $depth, ARRAY_FILTER_USE_KEY);
                $counters[$depth] = ($counters[$depth] ?? 0) + 1;
                $lines[$index] = $match[1].$counters[$depth].$match[3].$match[4];

                continue;
            }

            if (preg_match('/^\s*[-*+]\s+/u', $line) !== 1 && preg_match('/^\s+\S/u', $line) !== 1) {
                $counters = [];
            }
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------------------------
    // Citations and links
    // ---------------------------------------------------------------------------------------

    // "[SOURCE:12, SOURCE:13]", "[SOURCE 12]", "[DOCUMENT:12]" become canonical [SOURCE:n]
    // markers, and emphasis around them ("**[SOURCE:12]**", "*[SOURCE:12]*") is removed.
    private function normalizeCitationFormat(string $text): string
    {
        // A bracket that quotes a title before its id ("["Comptes d'operations" **SOURCE:624**]")
        // is a citation as well: the quoted text is the model's annotation, not a claim.
        $text = (string) preg_replace_callback('/\[[^\]\r\n]*?\bSOURCE\s*:?\s*\d+[^\]\r\n]*\]/iu', static function (array $match): string {
            preg_match_all('/SOURCE\s*:?\s*(\d+)/iu', $match[0], $ids);

            return implode('', array_map(static fn (string $id): string => "[SOURCE:{$id}]", array_unique($ids[1])));
        }, $text);

        $text = (string) preg_replace_callback(self::ANY_CITATION, static function (array $match): string {
            preg_match_all('/\d+/', $match[0], $ids);

            return $ids[0] === [] ? $match[0] : implode('', array_map(static fn (string $id): string => "[SOURCE:{$id}]", array_unique($ids[0])));
        }, $text);

        return (string) preg_replace('/(\*{1,2}|_{1,2})\s*((?:\[SOURCE:\d+\]\s*)+)\1/u', '$2', $text);
    }

    // A Markdown link to a documented host or to the official domain is reduced to its text;
    // any other link stays, and the link check removes it.
    private function unwrapDocumentedLinks(string $answer): string
    {
        $source = Str::ascii(mb_strtolower($this->context['raw']));
        $official = $this->officialRootDomains();

        return (string) preg_replace_callback('/\[([^\]\r\n]+)\]\((https?:\/\/[^\s\)]+)\)/iu', function (array $match) use ($source, $official): string {
            $root = $this->rootDomain((string) parse_url($match[2], PHP_URL_HOST));

            return $root !== '' && (str_contains($source, $root) || in_array($root, $official, true)) ? $match[1] : $match[0];
        }, $answer);
    }

    /**
     * @return list<string>
     */
    private function officialRootDomains(): array
    {
        // Also used outside a booted application (plain unit tests): no config there.
        $container = Container::getInstance();
        $hosts = $container->bound('config') ? (array) $container->make('config')->get('rag.allowed_source_hosts', []) : ['docs.mypvit.pro'];

        return array_values(array_unique(array_filter(array_map(fn (string $host): string => $this->rootDomain($host), array_map('strval', $hosts)))));
    }

    private function rootDomain(string $host): string
    {
        $labels = explode('.', strtolower(trim($host)));

        return count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : '';
    }

    private function citationLabel(string $document, string $section): string
    {
        $document = $this->safeMetadataLabel($document) ?: 'Document officiel';
        $section = $this->safeMetadataLabel($section) ?: 'Section non précisée';

        return $document.' / '.$section;
    }

    private function safeMetadataLabel(string $value): string
    {
        $value = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $value);
        $value = (string) preg_replace('/(?:\b(?:https?|ftp):\/\/|\bwww\.|\b(?:javascript|data|mailto):)\S*/iu', ' ', $value);
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}\[\]{}<>`|]+/u', ' ', $value);

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $value)), 180, '');
    }
}
