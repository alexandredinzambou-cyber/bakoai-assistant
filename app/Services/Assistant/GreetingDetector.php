<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Distinguishes small talk (greetings, well-being check-ins, thanks, farewells, "who are you"
 * questions) from real documentation questions, so callers can short-circuit retrieval/synthesis
 * for the former. Shared by AssistantService and MistralRawController so both entry points agree
 * on what counts as small talk.
 */
class GreetingDetector
{
    private const EXACT_PHRASES = [
        'salut', 'bonjour', 'bonsoir', 'coucou', 'hello', 'hi', 'hey', 'yo', 'wesh', 'cc',
        'ca va', 'cv', 'comment ca va', 'comment vas tu', 'comment allez vous',
        'tu vas bien', 'vous allez bien', 'quoi de neuf', 'quoi de 9', 'ca roule', 'ca gaze',
        'merci', 'merci beaucoup', 'merci bcp', 'ok merci', 'super merci', 'top merci',
        'au revoir', 'bye', 'a bientot', 'a plus', 'a plus tard', 'ciao', 'adieu',
        'bonne journee', 'bonne soiree', 'bonne nuit',
        'qui es tu', 't es qui', 'tu es qui', 'qui etes vous',
        'c est quoi ton nom', 'comment tu t appelles', 'comment t appelles tu',
        'que peux tu faire', 'tu sers a quoi', 'a quoi tu sers', 'tu fais quoi',
    ];

    private const GREETING_WORDS = 'salut|bonjour|bonsoir|coucou|hello|hi|hey|yo|wesh|cc';

    private const SOCIAL_PHRASES = 'ca va|comment tu vas|comment vas tu|comment allez vous|tu vas bien|vous allez bien'
        .'|quoi de neuf|ca roule|ca gaze|merci|bonne journee|bonne soiree|bonne nuit'
        .'|qui es tu|tu es qui|qui etes vous|que peux tu faire|tu sers a quoi|tu fais quoi';

    public function isGreeting(string $questionText): bool
    {
        $normalized = Str::of($questionText)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', '')
            ->squish()
            ->toString();

        if (in_array($normalized, self::EXACT_PHRASES, true)) {
            return true;
        }

        if ($this->containsTechnicalIntent($normalized)) {
            return false;
        }

        $containsSocialPhrase = preg_match('/\b(?:'.self::SOCIAL_PHRASES.')\b/u', $normalized) === 1;

        if ($containsSocialPhrase) {
            return true;
        }

        $startsWithGreeting = preg_match('/^(?:'.self::GREETING_WORDS.')\b/u', $normalized) === 1;
        $wordCount = count(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return $startsWithGreeting && $wordCount <= 5;
    }

    private function containsTechnicalIntent(string $normalized): bool
    {
        $aliases = collect((array) config('rag.payment_method_aliases', []))
            ->flatten()
            ->map(static fn (string $alias): string => preg_quote(Str::ascii(mb_strtolower($alias)), '/'))
            ->filter()
            ->implode('|');
        $paymentPattern = $aliases !== '' ? '|'.$aliases : '';

        return preg_match(
            '/\b(?:api|apis|authentification|callback|callbacks|cle|compte|documentation|email|erreur|erreurs|integration|integrer|inscription|marchand|paiement|production|remboursement|sandbox|secret|statut|transaction|webhook|webhooks'.$paymentPattern.')\b/u',
            $normalized,
        ) === 1;
    }
}
