<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ContextAssembler;
use App\Services\Assistant\GenerationPolicy;
use Tests\TestCase;

class GenerationPolicyTest extends TestCase
{
    public function test_questions_are_classified_by_what_they_ask(): void
    {
        $policy = new GenerationPolicy;

        foreach ([
            "C'est quoi le X-Secret ?" => GenerationPolicy::FACT,
            'Quel est le champ obligatoire pour initier un paiement PVIT ?' => GenerationPolicy::FACT,
            "le callback je peux le recevoir en xml ? c'est quel header qui gere ca" => GenerationPolicy::FACT,
            'Comment fonctionne le callback Airtel Money via PVIT ?' => GenerationPolicy::PROCEDURE,
            'Donne-moi un exemple de requete cURL pour initier un paiement' => GenerationPolicy::PROCEDURE,
            "j'ai une erreur 5406 quand je lance un export, ca veut dire quoi ?" => GenerationPolicy::DIAGNOSTIC,
            "pourquoi j'ai INVALID_MERCHANT_IP_ADDRESS depuis mon nouveau serveur ?" => GenerationPolicy::DIAGNOSTIC,
            "j'ai toujours pas recu le callback pour un paiement" => GenerationPolicy::DIAGNOSTIC,
        ] as $question => $type) {
            $this->assertSame($type, $policy->classify($question), $question);
        }
    }

    public function test_each_type_gets_one_length_rule_and_a_token_cap(): void
    {
        $policy = new GenerationPolicy;

        foreach ([GenerationPolicy::FACT, GenerationPolicy::PROCEDURE, GenerationPolicy::DIAGNOSTIC] as $type) {
            $prompt = $policy->systemPrompt($type);

            $this->assertStringNotContainsString('{LENGTH_RULE}', $prompt);
            $this->assertSame(1, substr_count($prompt, 'mots au maximum'), $type);
            $this->assertLessThan(560, str_word_count($prompt), 'the prompt stays short');
            $this->assertGreaterThan(0, $policy->maxTokens($type));
        }

        $this->assertLessThan($policy->maxTokens(GenerationPolicy::PROCEDURE), $policy->maxTokens(GenerationPolicy::FACT));
    }

    public function test_the_retry_prompt_carries_the_draft_and_the_passages_that_failed(): void
    {
        $prompt = (new GenerationPolicy)->retryPrompt(GenerationPolicy::PROCEDURE, 'Brouillon complet', [
            ['text' => 'La signature HMAC est dans X-Signature. [SOURCE:646]', 'reason' => 'fabricated_identifier', 'detail' => 'X-Signature'],
        ]);

        $this->assertStringContainsString('Brouillon complet', $prompt);
        $this->assertStringContainsString('La signature HMAC est dans X-Signature.', $prompt);
        $this->assertStringContainsString('identifiant absent des extraits : X-Signature', $prompt);
    }

    public function test_the_context_keeps_the_leading_chunks_and_drops_weak_ones_beyond_the_budget(): void
    {
        config(['rag.context_relative_score' => 0.6, 'rag.context_budget_chars' => 1200, 'rag.context_min_chunks' => 2]);
        $row = static fn (int $id, float $score, int $length): object => (object) [
            'chunk_id' => $id, 'ranking_score' => $score, 'contenu' => str_repeat('a', $length),
        ];

        $kept = (new ContextAssembler)->select(collect([
            $row(1, 1.00, 400), $row(2, 0.50, 400), $row(3, 0.80, 300), $row(4, 0.70, 400), $row(5, 0.30, 50),
        ]));

        // 1 and 2 are always kept; 3 is strong enough and fits; 4 exceeds the budget; 5 is weak.
        $this->assertSame([1, 2, 3], $kept->pluck('chunk_id')->all());
    }
}
