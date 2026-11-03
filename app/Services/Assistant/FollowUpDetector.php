<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Decides whether a question continues the previous turn ("Et pour Moov Money ?") and must be
 * read with it, or asks something new. Shared by AssistantService and MistralRawController so
 * both entry points agree on what a follow-up is.
 */
class FollowUpDetector
{
    // Words that point back to the previous turn whatever else the question says.
    private const STRONG_REFERENCES = '/\b(?:alors|aussi|autre|celui|celle|ces|cette|difference|donc|egalement|meme|pareil|pour lui|pour elle|precedent|suite)\b/u';

    // "ca", "cela", "c'est" also open questions that name their own subject ("pvit c'est quoi",
    // "comment ca se passe pour les remboursements"): they point back only when nothing else does.
    private const WEAK_REFERENCES = '/\b(?:ca|cela|c est|cest)\b/u';

    private const STOP_WORDS = [
        'afin', 'ainsi', 'alors', 'apres', 'aussi', 'avec', 'avant', 'cette', 'chez',
        'comment', 'comme', 'dans', 'des', 'donc', 'elle', 'elles', 'est', 'etre', 'eux',
        'faut', 'ils', 'lors', 'mais', 'meme', 'nous', 'par', 'pas', 'peut', 'plus',
        'pour', 'que', 'quel', 'quelle', 'quelles', 'quels', 'sans', 'ses', 'son', 'sont',
        'sur', 'une', 'vous', 'votre', 'aux', 'les', 'leur', 'leurs', 'via',
        'the', 'and', 'for', 'from', 'how', 'into', 'that', 'this', 'what', 'when', 'with',
    ];

    // Words that ask about the subject without naming one: "ca coute combien ?" still needs it.
    private const SUBJECTLESS_WORDS = [
        'cela', 'cest', 'quoi', 'combien', 'quand', 'pourquoi', 'passe', 'marche', 'fonctionne',
        'faire', 'fait', 'coute', 'prend', 'dure', 'temps', 'valable', 'possible', 'utiliser',
        'besoin', 'exemple', 'exactement', 'concretement', 'veut', 'dire', 'signifie',
    ];

    public function isFollowUp(string $question, ?string $previousQuestion = null): bool
    {
        $normalized = $this->normalize($question);
        $tokens = $this->contentTokens($normalized);

        if (preg_match('/^(?:autre (?:question|sujet)|nouve(?:lle question|au sujet)|changeons de sujet|changement de sujet|passons a|maintenant parlons de)\b/u', $normalized) === 1) {
            return false;
        }

        if (preg_match('/^(?:non\s+)?(?:je parle de|en fait je parle de|je voulais dire)\b/u', $normalized) === 1) {
            return false;
        }

        $actions = new ConversationActions;
        if ($actions->requestsExpansion($question)) {
            return $previousQuestion !== null;
        }
        if ($previousQuestion !== null && (new DurationConversion)->isConversionFollowUp($question)) {
            return true;
        }
        if ($previousQuestion !== null && preg_match('/\b(?:conservation|retention|delai)\b/u', $normalized) === 1
            && preg_match('/\b(?:repart|recommence|reinitialis)\w*\b/u', $normalized) === 1
            && preg_match('/\btelecharg\w*\b/u', $normalized) === 1) {
            return true;
        }
        if ($previousQuestion !== null && preg_match('/^(?:et )?(?:quels? (?:sont les )?(?:champs|parametres)(?: sont)? (?:obligatoires?|optionnels?|requis)|les (?:champs|parametres) (?:obligatoires?|optionnels?))\b/u', $normalized) === 1
            && (new ConversationPlan)->subject($question) === null) {
            return true;
        }
        if ($previousQuestion !== null && $actions->stepRequest($question) !== null) {
            return true;
        }
        if ($previousQuestion !== null && preg_match('/^(?:et (?:un|une) exemple|convertis cette duree|les (?:sept|\d+) jours commencent)\b/u', $normalized) === 1) {
            return true;
        }
        if ($actions->formatRequest($question) !== null) {
            return $previousQuestion !== null && $actions->refersToPreviousAnswer($question);
        }
        if ($previousQuestion !== null
            && preg_match('/\b(?:methode http|chemin|endpoint|verbe http)\b/u', $normalized) === 1
            && preg_match('/\b(?:remboursement|callback|export|paiement|solde|cle|secret|frais|prestashop)\b/u', $normalized) !== 1) {
            return true;
        }

        if ($previousQuestion !== null && (
            preg_match('/^(?:(?:et|mais|donc)\s+)?(?:il|elle|ils|elles)\b/u', $normalized) === 1
            || preg_match('/\b(?:le|la|les)\s+(?:recuper|retrouv|renouvel|consult|telecharg)\w*/u', $normalized) === 1
            || preg_match('/\b(?:ce (?:probleme|souci|cas)|sa |son |ses )/u', $normalized) === 1
            || (preg_match('/\b(?:resum\w*|recapitul\w*|reformul\w*|simplifi\w*|raccourci\w*)\b/u', $normalized) === 1
                && preg_match('/\b(?:cela|ca|reponse|en \w+ points?)\b/u', $normalized) === 1)
        )) {
            return true;
        }

        if ($previousQuestion !== null && preg_match('/\b(?:reste t il|reste t elle|est il|est elle|retrouver|retrouve|cet appel|cette requete|le recevoir|le recois|la recois|sa validite|sa duree)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match(self::STRONG_REFERENCES, $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:et|pour|quid|sinon)\b/u', $normalized) === 1 && $tokens !== [] && count($tokens) <= 4) {
            return true;
        }

        if (preg_match(self::WEAK_REFERENCES, $normalized) !== 1) {
            return false;
        }

        $previous = array_map(
            static fn (string $token): string => substr($token, 0, 5),
            $this->contentTokens($this->normalize((string) $previousQuestion)),
        );
        $subjects = array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, self::SUBJECTLESS_WORDS, true)
                && ! in_array(substr($token, 0, 5), $previous, true),
        );

        return $subjects === [];
    }

    /** Keep the resolved subject across successive short turns, separately from the raw question. */
    public function resolve(string $question, array $memory): string
    {
        if (! $this->isFollowUp($question, $memory['last_question'] ?? null)) {
            return $question;
        }

        $previous = $memory['topic_question'] ?? $memory['resolved_question'] ?? $memory['last_question'] ?? '';
        $recent = array_values(array_filter(array_slice((array) ($memory['recent_questions'] ?? []), -3), static fn ($item) => $item !== $previous));
        $recent[] = $question;

        return $previous === '' ? $question : Str::limit((string) $previous, 900, '').' '.implode(' ', array_unique($recent));
    }

    private function normalize(string $text): string
    {
        $normalized = Str::ascii(mb_strtolower($text));

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $normalized));
    }

    /**
     * @return list<string>
     */
    private function contentTokens(string $normalized): array
    {
        $stop = array_fill_keys(self::STOP_WORDS, true);

        return array_values(array_filter(
            preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $token): bool => strlen($token) >= 3 && ! isset($stop[$token]),
        ));
    }
}
