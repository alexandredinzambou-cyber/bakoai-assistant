<?php

namespace App\Services\Rag;

class EvalDatasetExpander
{
    /**
     * Expand an eval_questions.json payload's `templates` into concrete `items`,
     * substituting {moyen}/{operation} placeholders for every combination.
     *
     * @return list<array<string, mixed>>
     */
    public function expand(array $payload): array
    {
        $items = $payload['items'] ?? [];

        foreach ($payload['templates'] ?? [] as $template) {
            foreach ($template['moyens_paiement'] as $moyenPaiement) {
                foreach ($template['operations'] as $operation) {
                    foreach ($template['questions'] as $question) {
                        $items[] = [
                            'id' => 'auto-'.(count($items) + 1),
                            'category' => $template['category'] ?? 'escalation',
                            'moyen_paiement' => $moyenPaiement,
                            'operation' => $operation,
                            'question' => str_replace(
                                ['{moyen}', '{operation}'],
                                [$moyenPaiement, $operation],
                                $question,
                            ),
                            'must_include' => $template['must_include'] ?? [],
                            'must_not_include' => $template['must_not_include'] ?? [],
                            'expected_source_documents' => $template['expected_source_documents'] ?? [],
                            'expected_documentation_corpus_payment_method' => $template['expected_documentation_corpus_payment_method'] ?? null,
                            'should_escalate' => $template['should_escalate'] ?? true,
                        ];
                    }
                }
            }
        }

        return $items;
    }
}
