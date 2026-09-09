<?php

namespace Tests\Feature;

use App\Exceptions\KnowledgeVersionTransitionException;
use App\Models\Chunk;
use App\Models\DocumentApi;
use App\Models\KnowledgeVersion;
use App\Models\MoyenPaiement;
use App\Models\OpenApiSpec;
use App\Services\Knowledge\KnowledgeVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class KnowledgeVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_validates_and_atomically_activates_a_staging_version(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-v1', ['source' => 'crawler']);
        $document = $this->addValidDocument($version, 'kb-v1', 2);
        $openApiSpec = OpenApiSpec::create([
            'moyen_paiement_id' => $document->moyen_paiement_id,
            'document_id' => $document->id,
            'fichier_url' => 'https://docs.mypvit.pro/openapi/kb-v1.json',
            'source_key' => hash('sha256', 'https://docs.mypvit.pro/openapi/kb-v1.json'),
            'actif' => false,
        ]);

        $validated = $service->markValidated($version, ['reviewed_by' => 'qa']);
        $active = $service->activate($validated);

        $this->assertSame('active', $active->statut);
        $this->assertSame(1, $active->nombre_documents);
        $this->assertSame(2, $active->nombre_chunks);
        $this->assertNotNull($active->date_activation);
        $this->assertTrue($document->refresh()->actif);
        $this->assertTrue($openApiSpec->refresh()->actif);
        $this->assertSame('crawler', $active->metadata['source']);
        $this->assertSame('qa', $active->metadata['reviewed_by']);
        $this->assertSame(1, KnowledgeVersion::query()->where('statut', 'active')->count());
    }

    public function test_activating_a_new_version_demotes_the_previous_one_without_losing_history(): void
    {
        $service = app(KnowledgeVersionService::class);
        $first = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-v1')));
        $second = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-v2')));

        $this->assertSame('validated', $first->refresh()->statut);
        $this->assertNotNull($first->date_activation);
        $this->assertSame('active', $second->statut);
        $this->assertSame(1, KnowledgeVersion::query()->where('statut', 'active')->count());
    }

    public function test_activation_rolls_back_every_status_change_when_publication_fails(): void
    {
        $service = app(KnowledgeVersionService::class);
        $firstVersion = $this->createValidStagingVersion($service, 'kb-v1');
        $firstDocument = $firstVersion->documents()->firstOrFail();
        $first = $service->activate($service->markValidated($firstVersion));
        $second = $service->markValidated($this->createValidStagingVersion($service, 'kb-v2'));
        $event = 'eloquent.updating: '.KnowledgeVersion::class;

        Event::listen($event, function (KnowledgeVersion $version) use ($second): void {
            if ($version->id === $second->id && $version->statut === 'active') {
                throw new RuntimeException('Forced publication failure.');
            }
        });

        try {
            $service->activate($second);
            $this->fail('The forced publication failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced publication failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame('active', $first->refresh()->statut);
        $this->assertSame('validated', $second->refresh()->statut);
        $this->assertTrue($firstDocument->refresh()->actif);
        $this->assertSame(1, KnowledgeVersion::query()->where('statut', 'active')->count());
    }

    public function test_it_rolls_back_to_the_most_recent_previously_active_validated_version(): void
    {
        $service = app(KnowledgeVersionService::class);
        $first = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-v1')));
        $second = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-v2')));
        $third = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-v3')));

        $rolledBack = $service->rollback();

        $this->assertTrue($rolledBack->is($second));
        $this->assertSame('validated', $first->refresh()->statut);
        $this->assertSame('active', $second->refresh()->statut);
        $this->assertSame('validated', $third->refresh()->statut);
        $this->assertSame(1, KnowledgeVersion::query()->where('statut', 'active')->count());
    }

    public function test_failed_or_unvalidated_versions_cannot_replace_the_active_version(): void
    {
        $service = app(KnowledgeVersionService::class);
        $active = $service->activate($service->markValidated($this->createValidStagingVersion($service, 'kb-active')));
        $staging = $service->createStaging('kb-staging');
        $failed = $service->fail($service->createStaging('kb-failed'), 'Crawler unavailable');

        foreach ([$staging, $failed] as $candidate) {
            try {
                $service->activate($candidate);
                $this->fail('A non-validated version should not be activated.');
            } catch (KnowledgeVersionTransitionException $exception) {
                $this->assertSame('invalid_state', $exception->reasonCode);
            }
        }

        $this->assertSame('active', $active->refresh()->statut);
        $this->assertSame('failed', $failed->refresh()->statut);
        $this->assertSame('Crawler unavailable', $failed->metadata['failure_reason']);
        $this->assertSame(1, KnowledgeVersion::query()->where('statut', 'active')->count());
    }

    public function test_database_constraint_prevents_two_active_versions_outside_the_service(): void
    {
        KnowledgeVersion::create(['version' => 'kb-active-1', 'statut' => 'active']);

        $this->expectException(QueryException::class);

        KnowledgeVersion::create(['version' => 'kb-active-2', 'statut' => 'active']);
    }

    public function test_an_empty_version_is_rejected_and_the_active_corpus_is_preserved(): void
    {
        $service = app(KnowledgeVersionService::class);
        $activeVersion = $this->createValidStagingVersion($service, 'kb-active');
        $activeDocument = $activeVersion->documents()->firstOrFail();
        $service->activate($service->markValidated($activeVersion));
        $emptyVersion = $service->createStaging('kb-empty');

        try {
            $service->markValidated($emptyVersion);
            $this->fail('An empty version must not be validated.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('empty_version', $exception->reasonCode);
            $this->assertSame($exception->getMessage(), $exception->publicMessage());
        }

        $this->assertSame('active', $activeVersion->refresh()->statut);
        $this->assertTrue($activeDocument->refresh()->actif);
        $this->assertSame('staging', $emptyVersion->refresh()->statut);
    }

    public function test_activation_rechecks_embeddings_before_demoting_the_active_corpus(): void
    {
        $service = app(KnowledgeVersionService::class);
        $activeVersion = $this->createValidStagingVersion($service, 'kb-active');
        $activeDocument = $activeVersion->documents()->firstOrFail();
        $service->activate($service->markValidated($activeVersion));
        $candidate = $this->createValidStagingVersion($service, 'kb-candidate');
        $service->markValidated($candidate);
        DB::table('embeddings')
            ->whereIn('chunk_id', $candidate->documents()->firstOrFail()->chunks()->pluck('id'))
            ->update(['vecteur' => null]);

        try {
            $service->activate($candidate);
            $this->fail('A version with an invalid embedding must not be activated.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('invalid_chunk_embedding', $exception->reasonCode);
        }

        $this->assertSame('active', $activeVersion->refresh()->statut);
        $this->assertTrue($activeDocument->refresh()->actif);
        $this->assertSame('validated', $candidate->refresh()->statut);
    }

    public function test_rollback_rechecks_the_target_before_demoting_the_current_version(): void
    {
        $service = app(KnowledgeVersionService::class);
        $previous = $this->createValidStagingVersion($service, 'kb-previous');
        $previousDocument = $previous->documents()->firstOrFail();
        $service->activate($service->markValidated($previous));
        $current = $this->createValidStagingVersion($service, 'kb-current');
        $currentDocument = $current->documents()->firstOrFail();
        $service->activate($service->markValidated($current));
        DB::table('embeddings')
            ->whereIn('chunk_id', $previousDocument->chunks()->pluck('id'))
            ->delete();

        try {
            $service->rollback();
            $this->fail('Rollback must reject an incomplete previous corpus.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('invalid_chunk_embedding', $exception->reasonCode);
        }

        $this->assertSame('active', $current->refresh()->statut);
        $this->assertTrue($currentDocument->refresh()->actif);
        $this->assertSame('validated', $previous->refresh()->statut);
        $this->assertFalse($previousDocument->refresh()->actif);
    }

    public function test_preflight_requires_a_verified_non_conflicting_document(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-conflicted');
        $this->addValidDocument($version, 'kb-conflicted', hasKnownConflicts: true);

        $this->expectException(KnowledgeVersionTransitionException::class);
        $this->expectExceptionMessage('sans conflit connu');

        $service->markValidated($version);
    }

    public function test_preflight_rejects_a_partial_snapshot_with_an_unverified_document(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-partial');
        $this->addValidDocument($version, 'verified');
        $unverified = $this->addValidDocument($version, 'unverified');
        $unverified->forceFill(['link_verified_at' => null])->save();

        try {
            $service->markValidated($version);
            $this->fail('A partially verified snapshot must not be validated.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('unverified_document', $exception->reasonCode);
        }

        $this->assertSame('staging', $version->refresh()->statut);
    }

    public function test_preflight_requires_chunks_and_non_null_embeddings(): void
    {
        $service = app(KnowledgeVersionService::class);
        $withoutChunks = $service->createStaging('kb-without-chunks');
        $this->addDocument($withoutChunks, 'kb-without-chunks');

        try {
            $service->markValidated($withoutChunks);
            $this->fail('A document without chunks must be rejected.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('document_without_chunks', $exception->reasonCode);
        }

        $withoutEmbedding = $service->createStaging('kb-without-embedding');
        $document = $this->addDocument($withoutEmbedding, 'kb-without-embedding');
        Chunk::create(['document_id' => $document->id, 'contenu' => 'Passage incomplet.', 'position' => 1]);

        try {
            $service->markValidated($withoutEmbedding);
            $this->fail('A chunk without an embedding must be rejected.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('invalid_chunk_embedding', $exception->reasonCode);
        }
    }

    public function test_preflight_rejects_mixed_embedding_models(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-mixed-models');
        $document = $this->addDocument($version, 'kb-mixed-models');
        $first = Chunk::create(['document_id' => $document->id, 'contenu' => 'Premier passage.', 'position' => 1]);
        $second = Chunk::create(['document_id' => $document->id, 'contenu' => 'Second passage.', 'position' => 2]);
        $this->addEmbedding($first, 'provider:model-a');
        $this->addEmbedding($second, 'provider:model-b');

        try {
            $service->markValidated($version);
            $this->fail('Mixed embedding models must be rejected.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('mixed_embedding_models', $exception->reasonCode);
        }
    }

    public function test_preflight_rejects_an_embedding_model_that_retrieval_will_not_query(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-wrong-model');
        $this->addValidDocument($version, 'wrong-model', embeddingModel: 'nvidia:other-model');

        try {
            $service->markValidated($version);
            $this->fail('An index built with another model must not be published.');
        } catch (KnowledgeVersionTransitionException $exception) {
            $this->assertSame('embedding_model_mismatch', $exception->reasonCode);
        }
    }

    public function test_database_constraint_prevents_duplicate_sources_within_a_version(): void
    {
        $service = app(KnowledgeVersionService::class);
        $version = $service->createStaging('kb-unique-source');
        $this->addDocument($version, 'same-source');

        $this->expectException(QueryException::class);

        $this->addDocument($version, 'same-source');
    }

    private function createValidStagingVersion(
        KnowledgeVersionService $service,
        string $name,
    ): KnowledgeVersion {
        $version = $service->createStaging($name);
        $this->addValidDocument($version, $name);

        return $version;
    }

    private function addValidDocument(
        KnowledgeVersion $version,
        string $sourceName,
        int $chunkCount = 1,
        string $embeddingModel = 'local:local-hash-v1',
        bool $hasKnownConflicts = false,
    ): DocumentApi {
        $document = $this->addDocument($version, $sourceName, $hasKnownConflicts);

        foreach (range(1, $chunkCount) as $position) {
            $chunk = Chunk::create([
                'document_id' => $document->id,
                'contenu' => "Passage {$position} de {$sourceName}.",
                'position' => $position,
            ]);
            $this->addEmbedding($chunk, $embeddingModel);
        }

        return $document;
    }

    private function addDocument(
        KnowledgeVersion $version,
        string $sourceName,
        bool $hasKnownConflicts = false,
    ): DocumentApi {
        $payment = MoyenPaiement::firstOrCreate(['nom' => 'PVIT'], ['type' => 'passerelle']);

        return DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'knowledge_version_id' => $version->id,
            'titre' => "Document {$sourceName}",
            'lien_officiel' => "https://docs.mypvit.pro/fr/test/{$sourceName}",
            'link_verified_at' => now(),
            'has_known_conflicts' => $hasKnownConflicts,
            'actif' => false,
        ]);
    }

    private function addEmbedding(Chunk $chunk, string $model): void
    {
        $vector = '['.implode(',', array_fill(0, (int) config('rag.embedding_dimensions', 768), '0.1')).']';
        $bindings = [$chunk->id, $model, $vector];

        if (DB::getDriverName() === 'pgsql') {
            DB::insert(
                'INSERT INTO embeddings (chunk_id, modele, vecteur, created_at, updated_at) VALUES (?, ?, ?::vector, now(), now())',
                $bindings,
            );

            return;
        }

        DB::table('embeddings')->insert([
            'chunk_id' => $chunk->id,
            'modele' => $model,
            'vecteur' => $vector,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
