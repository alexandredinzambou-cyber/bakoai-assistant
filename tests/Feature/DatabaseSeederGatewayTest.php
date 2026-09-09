<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_methods_are_seeded_as_one_gateway_and_five_pvit_contexts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('moyens_paiement', ['nom' => 'PVIT', 'type' => 'passerelle']);

        foreach (['Airtel Money', 'Moov Money', 'Visa', 'Mastercard', 'GIMAC'] as $context) {
            $this->assertDatabaseHas('moyens_paiement', [
                'nom' => $context,
                'type' => 'canal_pvit',
            ]);
        }
    }
}
