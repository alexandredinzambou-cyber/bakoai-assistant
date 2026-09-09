<?php

namespace Tests\Unit;

use App\Services\Rag\PaymentScopeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentScopeResolverTest extends TestCase
{
    #[DataProvider('gatewayContexts')]
    public function test_every_payment_context_routes_to_the_pvit_documentation_corpus(string $paymentContext): void
    {
        $resolver = new PaymentScopeResolver;

        $this->assertSame('PVIT', $resolver->corpusFor($paymentContext));
    }

    public function test_gateway_name_and_one_channel_are_not_treated_as_two_competing_rails(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Comment intégrer Airtel Money avec la passerelle MyPVit ?',
        );

        $this->assertNull($resolution['error']);
        $this->assertSame('Airtel Money', $resolution['requested_payment_method']);
        $this->assertSame('PVIT', $resolution['corpus_payment_method']);
        $this->assertSame(['PVIT', 'Airtel Money'], $resolution['mentioned_payment_methods']);
    }

    public function test_a_pvit_filter_does_not_erase_the_channel_named_in_the_question(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Que documente PVIT pour Moov Money ?',
            'PVIT',
        );

        $this->assertNull($resolution['error']);
        $this->assertSame('Moov Money', $resolution['requested_payment_method']);
        $this->assertSame('PVIT', $resolution['corpus_payment_method']);
    }

    public function test_two_distinct_channels_still_require_clarification(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Airtel Money et Moov Money ont-ils le même comportement interne ?',
        );

        $this->assertSame('multiple_payment_methods', $resolution['error']);
        $this->assertNull($resolution['requested_payment_method']);
        $this->assertNull($resolution['corpus_payment_method']);
    }

    public function test_plural_behavior_comparison_is_not_mistaken_for_gateway_coverage(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Les moyens PVIT Airtel et Moov utilisent-ils les mêmes callbacks et statuts ?',
        );

        $this->assertSame('multiple_payment_methods', $resolution['error']);
        $this->assertNull($resolution['requested_payment_method']);
    }

    public function test_single_channel_names_do_not_change_the_shared_pvit_retrieval_question(): void
    {
        $resolver = new PaymentScopeResolver;
        $questions = collect(['Airtel Money', 'Moov Money', 'Visa', 'Mastercard', 'GIMAC'])
            ->map(fn (string $context): string => $resolver->retrievalQuestion(
                "Dans le cadre d'une integration {$context} via PVIT, comment l'adresse e-mail est-elle verifiee lors de l'inscription du compte marchand MyPVit ?",
            ));

        $this->assertCount(1, $questions->unique());
        $this->assertStringNotContainsString('airtel', $questions->first());
        $this->assertStringNotContainsString('pvit', $questions->first());
    }

    public function test_multi_channel_gateway_coverage_keeps_operator_names_as_search_evidence(): void
    {
        $question = 'PVIT prend-il en charge Airtel, Moov, Visa, Mastercard et GIMAC ?';

        $this->assertSame($question, (new PaymentScopeResolver)->retrievalQuestion($question));
    }

    public function test_a_multi_channel_coverage_list_is_resolved_at_gateway_level(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'PVIT prend-il en charge Airtel, Moov, Visa, Mastercard et GIMAC ?',
        );

        $this->assertNull($resolution['error']);
        $this->assertSame('PVIT', $resolution['requested_payment_method']);
        $this->assertSame('PVIT', $resolution['corpus_payment_method']);
    }

    public function test_coverage_words_do_not_hide_a_multi_channel_callback_comparison(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Dans la liste PVIT, Airtel et Moov utilisent-ils le même callback ?',
        );

        $this->assertSame('multiple_payment_methods', $resolution['error']);
        $this->assertNull($resolution['requested_payment_method']);
    }

    public function test_a_channel_filter_cannot_be_replaced_by_another_channel_in_the_question(): void
    {
        $resolution = (new PaymentScopeResolver)->resolve(
            'Que dit PVIT sur Visa ?',
            'Mastercard',
        );

        $this->assertSame('payment_scope_mismatch', $resolution['error']);
    }

    public static function gatewayContexts(): array
    {
        return [
            ['PVIT'],
            ['Airtel Money'],
            ['Moov Money'],
            ['Visa'],
            ['Mastercard'],
            ['GIMAC'],
        ];
    }
}
