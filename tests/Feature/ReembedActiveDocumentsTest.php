<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\DocumentApi;
use App\Models\MoyenPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReembedActiveDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://generativelanguage.test/v1beta/models/gemini-embedding-2:embedContent';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'embedding.dimensions' => 768,
            'embedding.retry_attempts' => 1,
            'embedding.retry_delay_ms' => 0,
            'embedding.providers.gemini' => [
                'base_url' => 'https://generativelanguage.test/v1beta',
                'api_key' => 'gemini-test-key',
                'model' => 'gemini-embedding-2',
                'timeout' => 5,
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_replaces_all_active_embeddings_atomically_with_gemini(): void
    {
        $chunks = $this->seedActiveDocument();
        Http::fake([self::URL => Http::response([
            'embedding' => ['values' => array_fill(0, 768, 0.5)],
        ])]);

        $this->artisan('rag:reembed', ['--provider' => 'gemini'])
            ->expectsOutputToContain('Re-embedded 2 chunks atomically with [gemini:gemini-embedding-2].')
            ->assertSuccessful();

        $this->assertSame(
            ['gemini:gemini-embedding-2'],
            DB::table('embeddings')->whereIn('chunk_id', $chunks)->distinct()->pluck('modele')->all(),
        );
        Http::assertSentCount(2);
    }

    public function test_it_keeps_every_existing_vector_when_preparation_fails(): void
    {
        $chunks = $this->seedActiveDocument();
        Http::fake([
            self::URL => Http::sequence()
                ->push(['embedding' => ['values' => array_fill(0, 768, 0.5)]], 200)
                ->push(['error' => ['message' => 'failure']], 400),
        ]);

        $this->artisan('rag:reembed', ['--provider' => 'gemini'])
            ->expectsOutputToContain('No embedding was changed in the database.')
            ->assertFailed();

        $this->assertSame(
            ['local:local-hash-v1'],
            DB::table('embeddings')->whereIn('chunk_id', $chunks)->distinct()->pluck('modele')->all(),
        );
    }

    /**
     * @return list<int>
     */
    private function seedActiveDocument(): array
    {
        $payment = MoyenPaiement::create(['nom' => 'PVIT', 'type' => 'api']);
        $document = DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'titre' => 'Présentation PVIT',
            'lien_officiel' => 'https://docs.mypvit.pro/fr/intro/getting-started',
            'actif' => true,
            'link_verified_at' => now(),
        ]);

        $chunks = [
            Chunk::create(['document_id' => $document->id, 'contenu' => 'Premier passage.', 'position' => 1]),
            Chunk::create(['document_id' => $document->id, 'contenu' => 'Second passage.', 'position' => 2]),
        ];

        $placeholderVector = '['.implode(',', array_fill(0, 768, 0)).']';

        foreach ($chunks as $chunk) {
            DB::table('embeddings')->insert([
                'chunk_id' => $chunk->id,
                'modele' => 'local:local-hash-v1',
                'vecteur' => $placeholderVector,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return array_map(static fn (Chunk $chunk): int => $chunk->id, $chunks);
    }
}
