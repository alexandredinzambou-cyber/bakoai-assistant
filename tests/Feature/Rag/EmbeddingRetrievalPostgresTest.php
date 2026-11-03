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

    public function test_search_bridges_a_single_missing_section_and_demotes_heading_only_chunks(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'This test requires the pgvector-backed Postgres connection.');

        $embeddingService = $this->app->make(EmbeddingService::class);
        $ids = $this->seedSections('Webhook de notification de statut', [
            1 => ['Webhook de notification', "## Webhook de notification

PVit envoie un webhook callback a votre URL avec le statut final de la transaction."],
            2 => ['Accuse attendu', "## Accuse attendu

Repondez HTTP 200 avec un echo du transactionId et du code recus."],
            3 => ['Regles du webhook', "## Regles du webhook

Traitez chaque webhook callback de facon idempotente sur votre serveur."],
            4 => ['Etapes du webhook callback', '## Etapes du webhook callback'],
        ], $embeddingService);
        $this->seedDocument(
            'Guide Post-Production',
            'La bascule vers la production necessite de verifier le domaine, le certificat TLS et la liste blanche des adresses IP sortantes.',
            $embeddingService,
        );

        $results = $this->app->make(RetrievalService::class)->search(
            question: 'Comment fonctionne le webhook callback ?',
            limit: 2,
        );

        $returned = $results->pluck('chunk_id')->map(fn ($id): int => (int) $id)->all();

        $this->assertCount(3, $returned);
        $this->assertEqualsCanonicalizing([$ids[1], $ids[3]], array_slice($returned, 0, 2));
        $this->assertSame($ids[2], $returned[2]);
        $this->assertTrue((bool) $results->last()->is_gap_bridge);
        $this->assertSame([$ids[1], $ids[3]], $results->last()->bridge_seed_chunk_ids);
        $this->assertNotContains($ids[4], $returned);

        config(['rag.gap_bridge_max_rows' => 0]);

        $withoutBridge = $this->app->make(RetrievalService::class)->search(
            question: 'Comment fonctionne le webhook callback ?',
            limit: 2,
        );

        $this->assertCount(2, $withoutBridge);
        $this->assertFalse($withoutBridge->contains(fn ($row): bool => (bool) ($row->is_gap_bridge ?? false)));
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $sections  position => [section, contenu]
     * @return array<int, int> position => chunk id
     */
    private function seedSections(string $titre, array $sections, EmbeddingService $embeddingService): array
    {
        $payment = MoyenPaiement::firstOrCreate(['nom' => 'PVIT'], ['type' => 'api']);
        $document = DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'titre' => $titre,
            'lien_officiel' => 'https://docs.mypvit.pro/fr/'.Str::slug($titre),
            'actif' => true,
            'link_verified_at' => now(),
        ]);
        $ids = [];

        foreach ($sections as $position => [$section, $contenu]) {
            $chunk = Chunk::create([
                'document_id' => $document->id,
                'section' => $section,
                'contenu' => $contenu,
                'position' => $position,
            ]);
            $vector = $embeddingService->embedForDocument($contenu, $titre);
            DB::table('embeddings')->insert([
                'chunk_id' => $chunk->id,
                'modele' => $embeddingService->lastModel(),
                'vecteur' => $embeddingService->toSqlLiteral($vector),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ids[$position] = (int) $chunk->id;
        }

        return $ids;
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
