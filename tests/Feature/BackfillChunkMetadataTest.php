<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\DocumentApi;
use App\Models\MoyenPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillChunkMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_filters_without_changing_chunk_content(): void
    {
        $payment = MoyenPaiement::create(['nom' => 'PVIT', 'type' => 'passerelle']);
        $document = DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'titre' => 'OpenAPI',
            'lien_officiel' => 'https://docs.mypvit.pro/openapi/pvit.json',
        ]);
        $content = "POST /v2/payments/initiate\nInitiation de paiement sandbox. Statut HTTP 401.";
        $chunk = Chunk::create([
            'document_id' => $document->id,
            'contenu' => $content,
            'section' => 'Paiement',
            'position' => 1,
        ]);

        $this->artisan('rag:backfill-metadata')->assertSuccessful();

        $chunk->refresh();
        $this->assertSame($content, $chunk->contenu);
        $this->assertSame('PVIT', $chunk->payment_method);
        $this->assertSame('paiement', $chunk->section_slug);
        $this->assertSame('error', $chunk->content_type);
        $this->assertNotNull($chunk->search_text);
        $this->assertContains('error', $chunk->index_terms);
        $this->assertSame('paiement', $chunk->operation);
        $this->assertSame('sandbox', $chunk->environment);
        $this->assertSame('POST', $chunk->http_method);
        $this->assertSame('/v2/payments/initiate', $chunk->endpoint);
        $this->assertSame(401, $chunk->http_status);
        $this->assertNotNull($chunk->token_count);
        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_dry_run_does_not_update_rows(): void
    {
        $document = DocumentApi::create(['titre' => 'Document']);
        $chunk = Chunk::create([
            'document_id' => $document->id,
            'contenu' => 'Texte sans metadonnees.',
            'position' => 1,
        ]);

        $this->artisan('rag:backfill-metadata', ['--dry-run' => true])
            ->expectsOutputToContain('1 chunk(s) eligible')
            ->assertSuccessful();

        $this->assertNull($chunk->refresh()->token_count);
    }
}
