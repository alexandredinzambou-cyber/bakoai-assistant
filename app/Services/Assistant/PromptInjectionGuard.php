<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

final class PromptInjectionGuard
{
    private const QUESTION_REFUSAL = 'Je ne peux pas suivre une demande qui tente de modifier mes regles, mon role ou mes garde-fous. Reformulez votre question en vous limitant au probleme d integration PVIT a diagnostiquer.';

    private const CONTEXT_REFUSAL = 'Les passages documentaires recuperes contiennent des instructions non fiables susceptibles de modifier le comportement de l assistant. Ils ont ete bloques et je recommande d ouvrir un ticket afin que la base documentaire soit verifiee.';

    private const OUTPUT_REFUSAL = 'La reponse generee contenait des instructions susceptibles de contourner les garde-fous de l assistant. Elle a ete bloquee et je recommande d ouvrir un ticket de support.';

    /**
     * Detect high-confidence attempts to override the instruction hierarchy, exfiltrate
     * hidden prompts or disable application guardrails. Unicode compatibility
     * normalization and removal of format characters prevent common zero-width/full-width
     * evasions without treating ordinary technical prose as an injection by itself.
     */
    public function containsPromptInjection(string $text): bool
    {
        $normalized = $this->normalize($text);

        if (preg_match('/(?:^|\n)\s*(?:system|developer|assistant)\s*(?:message|prompt)?\s*:/mu', $normalized) === 1
            || preg_match('/<\|\s*(?:system|developer|assistant)\s*\|>|\[\/?\s*(?:system|developer|assistant)\s*\]/iu', $normalized) === 1) {
            return true;
        }

        $flat = trim((string) preg_replace('/[^a-z0-9\n]+/u', ' ', $normalized));
        $flat = (string) preg_replace('/[ \t]+/u', ' ', $flat);

        if ($flat === '') {
            return false;
        }

        $patterns = [
            // Direct instruction-hierarchy overrides in French or English.
            '/\b(?:ignore|ignorer|ignorez|disregard|forget|oublie|oublier|oubliez)\b.{0,100}\b(?:all |toutes? les |les )?(?:previous|prior|precedentes?|anterieures?|systeme|system|developer)\s+(?:instructions?|consignes?|regles?|messages?|prompts?)\b/su',
            '/\b(?:ignore|ignorer|ignorez|disregard|forget|oublie|oublier|oubliez)\b.{0,100}\b(?:instructions?|consignes?|regles?|messages?|prompts?)\s+(?:previous|prior|precedentes?|anterieures?|systeme|system|developer)\b/su',
            '/\b(?:ne tiens|ne tenez|don t|do not)\b.{0,50}\b(?:compte|follow)\b.{0,80}\b(?:instructions?|consignes?|regles?|system|systeme)\b/su',
            '/\b(?:new|nouveau|nouvelle|following|suivantes?)\s+(?:instructions?|consignes?|regles?|prompt)\b.{0,100}\b(?:override|replace|supersede|annulent?|remplacent?|priorite|priority)\b/su',

            // Role reassignment is not needed for a PVIT integration question.
            '/\b(?:you are now|act as|pretend to be|tu es maintenant|vous etes maintenant|agis comme|agissez comme|change ton role|changez votre role|nouveau role)\b/su',

            // Attempts to reveal hidden instructions, policies or secrets.
            '/\b(?:reveal|show|print|display|repeat|copy|expose|leak|divulgue|divulguer|revele|reveler|affiche|afficher|recopie|recopier|repete|repeter)\b.{0,100}\b(?:system prompt|developer message|hidden prompt|hidden instructions|prompt systeme|message developpeur|instructions? (?:internes?|cachees?|systeme)|regles? internes?)\b/su',

            // Explicit disabling or bypass of the safeguards required by this application.
            '/\b(?:bypass|disable|remove|circumvent|override|contourne|contourner|desactive|desactiver|supprime|supprimer|ignore|ignorer)\b.{0,100}\b(?:guardrails?|safeguards?|safety|security|citations?|restrictions?|garde fous?|securite|regles? de securite|interdiction de code)\b/su',

        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $flat) === 1) {
                return true;
            }
        }

        return false;
    }

    public function questionRefusal(): string
    {
        return self::QUESTION_REFUSAL;
    }

    public function contextRefusal(): string
    {
        return self::CONTEXT_REFUSAL;
    }

    public function outputRefusal(): string
    {
        return self::OUTPUT_REFUSAL;
    }

    private function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);

        return Str::ascii(mb_strtolower($text));
    }
}
