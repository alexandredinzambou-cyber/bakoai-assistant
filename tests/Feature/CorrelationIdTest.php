<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    public function test_api_responses_receive_a_correlation_id(): void
    {
        $response = $this->getJson('/api/status');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            (string) $response->headers->get('X-Correlation-ID'),
        );
    }

    public function test_a_valid_client_correlation_id_is_preserved(): void
    {
        $response = $this
            ->withHeader('X-Correlation-ID', 'partner-request-1234')
            ->getJson('/api/status');

        $response->assertOk()->assertHeader('X-Correlation-ID', 'partner-request-1234');
    }

    public function test_an_invalid_client_correlation_id_is_replaced(): void
    {
        $response = $this
            ->withHeader('X-Correlation-ID', 'short')
            ->getJson('/api/status');

        $response->assertOk();
        $this->assertNotSame('short', $response->headers->get('X-Correlation-ID'));
    }

    public function test_a_correlation_id_longer_than_the_audit_column_is_replaced(): void
    {
        $tooLong = str_repeat('a', 65);
        $response = $this->withHeader('X-Correlation-ID', $tooLong)->getJson('/api/status');

        $response->assertOk();
        $this->assertNotSame($tooLong, $response->headers->get('X-Correlation-ID'));
        $this->assertLessThanOrEqual(64, strlen((string) $response->headers->get('X-Correlation-ID')));
    }
}
