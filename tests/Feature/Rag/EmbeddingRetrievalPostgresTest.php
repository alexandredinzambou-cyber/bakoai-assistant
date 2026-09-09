<?php

namespace Tests\Feature\Rag;

use App\Models\Chunk;
use App\Models\DocumentApi;
use App\Models\MoyenPaiement;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmbeddingRetrievalPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgvector_cosine_search_ranks_the_lexically_closest_chunk_first(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'This test requires the pgvector-backed Postgres connection.');

        $embeddingService = $this->app->make(EmbeddingService::class);

        $target = $this->seedDocument(
            'Renouvellement de secret',
            'Pour renouveler le secret API MyPVit, transmettez le parametre renew-secret via le transport HTTPS defini par la passerelle PVIT.',
            $embeddingService,
        );

        $distractor = $this->seedDocument(
            'Guide Post-Production',
            'La bascule vers la production necessite de verifier le domaine, le certificat TLS et la liste blanche des adresses IP sortantes.',
            $embeddingService,
        );

        $results = $this->app->make(RetrievalService::class)->search(
            question: 'Quel parametre dois-je transmettre pour renouveler le secret API ?',
            limit: 5,
            vectorOnly: true,
        );

        $this->assertNotEmpty($results, 'Expected at least one chunk to be returned by the vector search.');
        $this->assertSame($target, $results->first()->document_titre);
        $this->assertLessThan(
            $results->firstWhere('document_titre', $distractor)->distance,
            $results->first()->distance,
            'The lexically related chunk should sit at a smaller cosine distance than the unrelated one.',
        );
    }

    private function seedDocument(string $titre, string $contenu, EmbeddingService $embeddingService): string
    {
        $payment = MoyenPaiement::firstOrCreate(['nom' => 'PVIT'], ['type' => 'api']);
        $document = DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'titre' => $titre,
            'lien_officiel' => 'https://docs.mypvit.pro/fr/'.Str::slug($titre),
            'actif' => true,
            'link_verified_at' => now(),
        ]);

        $chunk = Chunk::create([
            'document_id' => $document->id,
            'contenu' => $contenu,
            'position' => 1,
        ]);

        $vector = $embeddingService->embedForDocument($contenu, $titre);
        DB::table('embeddings')->insert([
            'chunk_id' => $chunk->id,
            'modele' => $embeddingService->lastModel(),
            'vecteur' => $embeddingService->toSqlLiteral($vector),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $titre;
    }
}
