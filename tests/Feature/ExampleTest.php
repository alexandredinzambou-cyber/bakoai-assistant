<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/mistral-raw');

        $this->get('/mistral-raw')
            ->assertOk()
            ->assertSee('PVIT Assistant');

        // The removed "Bureau d'intégration" page now forwards to PVIT Assistant.
        $this->get('/assistant')->assertRedirect('/mistral-raw');
    }
}
