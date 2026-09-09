<?php

namespace App\Services\Rag;

use Illuminate\Support\Str;

final class PaymentScopeResolver
{
    /**
     * Keep the requested payment context while resolving the single
     * documentation corpus used for retrieval.
     *
     * @return array{
     *     requested_payment_method: ?string,
     *     corpus_payment_method: ?string,
     *     mentioned_payment_methods: list<string>,
     *     error: ?string
     * }
     */
    public function resolve(string $question, ?string $explicitPaymentMethod = null): array
    {
        $mentioned = $this->mentionedPaymentMethods($question);
        $gateway = (string) config('rag.documentation_corpus_payment_method', 'PVIT');
        $mentionedContexts = array_values(array_filter(
            $mentioned,
            static fn (string $paymentMethod): bool => $paymentMethod !== $gateway,
        ));

        if (count($mentionedContexts) > 1
            && $this->isGatewayCoverageQuestion($question, $explicitPaymentMethod, $gateway)) {
            return $this->resolution($gateway, $mentioned, null);
        }

        if (count($mentionedContexts) > 1) {
            return $this->resolution(null, $mentioned, 'multiple_payment_methods');
        }

        $mentionedContext = $mentionedContexts[0] ?? null;
        $explicitContext = $explicitPaymentMethod !== null && $explicitPaymentMethod !== $gateway
            ? $explicitPaymentMethod
            : null;

        if ($explicitContext !== null
            && $mentionedContext !== null
            && $explicitContext !== $mentionedContext) {
            return $this->resolution(null, $mentioned, 'payment_scope_mismatch');
        }

        $requestedPaymentMethod = $explicitContext
            ?? $mentionedContext
            ?? $explicitPaymentMethod
            ?? (in_array($gateway, $mentioned, true) ? $gateway : null);

        if ($requestedPaymentMethod === null) {
            return $this->resolution(null, $mentioned, 'payment_scope_required');
        }

        return $this->resolution($requestedPaymentMethod, $mentioned, null);
    }

    public function corpusFor(?string $requestedPaymentMethod): ?string
    {
        if ($requestedPaymentMethod === null) {
            return null;
        }

        $routes = (array) config('rag.documentation_scope_by_payment_method', []);

        return (string) ($routes[$requestedPaymentMethod] ?? $requestedPaymentMethod);
    }

    /**
     * Remove a single payment context from the semantic query because PVIT owns
     * the shared procedure. Keep all names for a genuine gateway-coverage query,
     * where the operator list itself is the fact being searched.
     */
    public function retrievalQuestion(string $question): string
    {
        $gateway = (string) config('rag.documentation_corpus_payment_method', 'PVIT');
        $mentionedContexts = array_values(array_filter(
            $this->mentionedPaymentMethods($question),
            static fn (string $paymentMethod): bool => $paymentMethod !== $gateway,
        ));

        if (count($mentionedContexts) > 1) {
            return $question;
        }

        $normalized = Str::ascii(mb_strtolower($question));
        $aliases = collect((array) config('rag.payment_method_aliases', []))
            ->flatten()
            ->map(static fn (string $alias): string => Str::ascii(mb_strtolower($alias)))
            ->filter()
            ->sortByDesc(static fn (string $alias): int => strlen($alias));

        foreach ($aliases as $alias) {
            $pattern = collect(preg_split('/[^a-z0-9]+/', $alias, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->map(static fn (string $part): string => preg_quote($part, '/'))
                ->implode('[^a-z0-9]+');

            if ($pattern !== '') {
                $normalized = (string) preg_replace('/(?<![a-z0-9])'.$pattern.'(?![a-z0-9])/u', ' ', $normalized);
            }
        }

        $normalized = Str::squish($normalized);

        return $normalized !== '' ? $normalized : $question;
    }

    /**
     * @return list<string>
     */
    public function mentionedPaymentMethods(string $question): array
    {
        $normalizedQuestion = Str::of($question)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->prepend(' ')
            ->append(' ')
            ->toString();
        $aliases = (array) config('rag.payment_method_aliases', []);

        return collect((array) config('rag.payment_methods', []))
            ->filter(function (string $paymentMethod) use ($aliases, $normalizedQuestion): bool {
                return collect($aliases[$paymentMethod] ?? [$paymentMethod])
                    ->contains(function (string $alias) use ($normalizedQuestion): bool {
                        $normalizedAlias = Str::of($alias)
                            ->lower()
                            ->ascii()
                            ->replaceMatches('/[^a-z0-9]+/', ' ')
                            ->squish()
                            ->toString();

                        return $normalizedAlias !== ''
                            && str_contains($normalizedQuestion, ' '.$normalizedAlias.' ');
                    });
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $mentioned
     * @return array{
     *     requested_payment_method: ?string,
     *     corpus_payment_method: ?string,
     *     mentioned_payment_methods: list<string>,
     *     error: ?string
     * }
     */
    private function resolution(?string $requested, array $mentioned, ?string $error): array
    {
        return [
            'requested_payment_method' => $requested,
            'corpus_payment_method' => $this->corpusFor($requested),
            'mentioned_payment_methods' => $mentioned,
            'error' => $error,
        ];
    }

    private function isGatewayCoverageQuestion(
        string $question,
        ?string $explicitPaymentMethod,
        string $gateway,
    ): bool {
        if ($explicitPaymentMethod !== null && $explicitPaymentMethod !== $gateway) {
            return false;
        }

        $normalized = Str::ascii(mb_strtolower($question));
        $gatewayIsExplicit = $explicitPaymentMethod === $gateway
            || in_array($gateway, $this->mentionedPaymentMethods($question), true);
        $asksForCoverage = preg_match(
            '/\b(?:couverture|liste|disponibles?|operateurs?|moyens?)\b|\b(?:prend|pris|prendre)\b.{0,20}\ben charge\b|\b(?:supporte|accepte)(?:nt|e|es|t il)?\b/u',
            $normalized,
        ) === 1;
        $asksForBehaviorComparison = preg_match(
            '/\b(?:callbacks?|comportements?|identiques?|memes?|differences?|differents?|comparaisons?|comparer|flux|erreurs?|remboursements?|authentifications?|parametres?|statuts?|fonctionnements?|processus)\b/u',
            $normalized,
        ) === 1;

        return $gatewayIsExplicit && $asksForCoverage && ! $asksForBehaviorComparison;
    }
}
