<?php

namespace Tests\Feature;

use App\Models\DocumentApi;
use App\Models\OpenApiSpec;
use App\Services\Knowledge\KnowledgeVersionService;
use App\Services\Rag\OpenApiIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenApiIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_indexes_only_safe_explanatory_prose_and_records_traceable_metadata(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/payments.json';
        $path = $this->writeSpecification('payments.json', [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API Paiements PVIT',
                'version' => '2026-08',
                'description' => 'Cette prose dissimule SELECT 1 et ne doit pas etre indexee.',
            ],
            'paths' => [
                '/payments/{paymentId}' => [
                    'get' => [
                        'summary' => 'Consulter un paiement existant.',
                        'description' => 'Cette prose dissimule lambda payment: payment.status et doit etre retiree.',
                        'parameters' => [[
                            'name' => 'paymentId',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Cette prose dissimule [payment.id for payment in payments] et doit etre retiree.',
                            'example' => 'secret-example-must-not-be-indexed',
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Cette prose dissimule new Payment() et doit etre retiree.',
                                'content' => [
                                    'application/json' => [
                                        'example' => ['secret' => 'must-not-be-indexed'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->fakeOfficialFile($sourceUrl, $path);

        try {
            $result = app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);

            $this->assertSame('3.1.0', $result['openapi_version']);
            $this->assertSame('2026-08', $result['api_version']);
            $this->assertSame(1, $result['paths']);
            $this->assertSame(1, $result['operations']);

            $document = DocumentApi::query()->with('chunks')->findOrFail($result['document_id']);
            $indexedText = $document->chunks->pluck('contenu')->implode("\n");
            $spec = OpenApiSpec::query()->findOrFail($result['open_api_spec_id']);

            $this->assertTrue($document->actif);
            $this->assertSame($sourceUrl, $document->lien_officiel);
            $this->assertSame($document->id, $spec->document_id);
            $this->assertSame(hash('sha256', File::get($path)), $spec->checksum);
            $this->assertTrue($spec->actif);
            $this->assertSame(1, $spec->paths_count);
            $this->assertSame(1, $spec->operations_count);
            $this->assertStringContainsString('Consulter un paiement existant.', $indexedText);
            $this->assertStringContainsString('Le parametre nomme paymentId est obligatoire', $indexedText);
            $this->assertStringNotContainsString('SELECT 1', $indexedText);
            $this->assertStringNotContainsString('lambda payment', $indexedText);
            $this->assertStringNotContainsString('payment.id for payment', $indexedText);
            $this->assertStringNotContainsString('new Payment', $indexedText);
            $this->assertStringNotContainsString('secret-example-must-not-be-indexed', $indexedText);
            $this->assertStringNotContainsString('must-not-be-indexed', $indexedText);
            $this->assertStringNotContainsString('{paymentId}', $indexedText);
            $this->assertStringNotContainsString('application/json', $indexedText);
        } finally {
            File::delete($path);
        }
    }

    public function test_reingestion_preserves_history_and_leaves_only_the_new_spec_active(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/payments.json';
        $path = $this->writeSpecification('payments-history.json', $this->minimalSpecification('v1'));
        $this->fakeOfficialFile($sourceUrl, $path);

        try {
            $first = app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);
            File::put($path, json_encode($this->minimalSpecification('v2'), JSON_THROW_ON_ERROR));
            $second = app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);

            $this->assertNotSame($first['document_id'], $second['document_id']);
            $this->assertFalse(OpenApiSpec::query()->findOrFail($first['open_api_spec_id'])->actif);
            $this->assertTrue(OpenApiSpec::query()->findOrFail($second['open_api_spec_id'])->actif);
            $this->assertFalse(DocumentApi::query()->findOrFail($first['document_id'])->actif);
            $this->assertTrue(DocumentApi::query()->findOrFail($second['document_id'])->actif);
            $this->assertDatabaseCount('open_api_specs', 2);
        } finally {
            File::delete($path);
        }
    }

    public function test_openapi_can_be_staged_and_published_with_its_knowledge_version(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/staged-payments.json';
        $path = $this->writeSpecification('staged-payments.json', $this->minimalSpecification('v2'));
        $this->fakeOfficialFile($sourceUrl, $path);
        $versions = app(KnowledgeVersionService::class);
        $version = $versions->createStaging('kb-openapi-v2');

        try {
            $result = app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                $sourceUrl,
                null,
                $version->id,
            );

            $document = DocumentApi::findOrFail($result['document_id']);
            $specification = OpenApiSpec::findOrFail($result['open_api_spec_id']);
            $this->assertFalse($document->actif);
            $this->assertFalse($specification->actif);
            $this->assertSame($version->id, $document->knowledge_version_id);

            $versions->activate($versions->markValidated($version));

            $this->assertTrue($document->refresh()->actif);
            $this->assertTrue($specification->refresh()->actif);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_non_openapi_json_before_any_database_write(): void
    {
        $path = $this->writeSpecification('not-openapi.json', [
            'info' => ['title' => 'Faux document', 'version' => '1'],
            'paths' => [],
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Version OpenAPI non prise en charge');

            app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                'https://docs.mypvit.pro/openapi/invalid.json',
            );
        } finally {
            File::delete($path);
            $this->assertDatabaseCount('documents_api', 0);
            $this->assertDatabaseCount('open_api_specs', 0);
        }
    }

    public function test_it_supports_a_local_path_item_reference_without_following_external_sources(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/referenced.json';
        $specification = $this->minimalSpecification('v3');
        $specification['openapi'] = '3.2.0';
        $specification['paths'] = [
            '/payments' => ['$ref' => '#/components/pathItems/Payments'],
        ];
        $specification['components'] = [
            'pathItems' => [
                'Payments' => [
                    'get' => [
                        'summary' => 'Lister les paiements disponibles.',
                        'responses' => [
                            '200' => ['description' => 'La liste est disponible.'],
                        ],
                    ],
                ],
            ],
        ];
        $path = $this->writeSpecification('referenced.json', $specification);
        $this->fakeOfficialFile($sourceUrl, $path);

        try {
            $result = app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);
            $document = DocumentApi::query()->with('chunks')->findOrFail($result['document_id']);

            $this->assertSame('3.2.0', $result['openapi_version']);
            $this->assertSame(1, $result['operations']);
            $this->assertStringContainsString(
                'Lister les paiements disponibles.',
                $document->chunks->pluck('contenu')->implode("\n"),
            );
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $sourceUrl);
            Http::assertSentCount(2);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_a_json_array_as_the_document_root(): void
    {
        $path = $this->writeSpecification('array-root.json', []);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('doit etre un objet JSON');

            app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                'https://docs.mypvit.pro/openapi/array.json',
            );
        } finally {
            File::delete($path);
            $this->assertDatabaseCount('documents_api', 0);
            $this->assertDatabaseCount('open_api_specs', 0);
        }
    }

    public function test_it_rejects_an_openapi_url_outside_the_official_allowlist(): void
    {
        $path = $this->writeSpecification('outside-host.json', $this->minimalSpecification('v1'));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('URL OpenAPI officielle non autorisee');

            app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                'https://evil.example/openapi.json',
            );
        } finally {
            File::delete($path);
            $this->assertDatabaseCount('documents_api', 0);
            $this->assertDatabaseCount('open_api_specs', 0);
        }
    }

    public function test_it_rejects_a_path_without_a_leading_slash_and_an_empty_operation_without_responses(): void
    {
        $invalidPath = $this->minimalSpecification('v1');
        $invalidPath['paths'] = [
            'not-a-valid-path' => [
                'get' => [],
            ],
        ];
        $path = $this->writeSpecification('invalid-path.json', $invalidPath);

        try {
            app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                'https://docs.mypvit.pro/openapi/invalid-path.json',
            );
            $this->fail('A path without a leading slash must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('doit commencer par une barre oblique', $exception->getMessage());
        } finally {
            File::delete($path);
        }

        $emptyOperation = $this->minimalSpecification('v1');
        $emptyOperation['paths'] = [
            '/payments' => [
                'get' => [],
            ],
        ];
        $path = $this->writeSpecification('empty-operation.json', $emptyOperation);

        try {
            app(OpenApiIngestionService::class)->ingestFile(
                $path,
                'PVIT',
                'https://docs.mypvit.pro/openapi/empty-operation.json',
            );
            $this->fail('An empty operation without responses must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('doit etre non vide et definir responses', $exception->getMessage());
        } finally {
            File::delete($path);
        }

        $this->assertDatabaseCount('documents_api', 0);
        $this->assertDatabaseCount('open_api_specs', 0);
        Http::assertNothingSent();
    }

    public function test_remote_verification_accepts_reordered_equivalent_json_and_uses_get(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/reordered.json';
        $specification = $this->minimalSpecification('v4');
        $path = $this->writeSpecification('reordered.json', $specification);
        $remote = [
            'paths' => $specification['paths'],
            'info' => [
                'version' => $specification['info']['version'],
                'title' => $specification['info']['title'],
            ],
            'openapi' => $specification['openapi'],
        ];

        Http::fake([
            $sourceUrl => Http::response(
                json_encode($remote, JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/openapi+json'],
            ),
        ]);

        try {
            $result = app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);

            $this->assertTrue($result['actif']);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $sourceUrl);
        } finally {
            File::delete($path);
        }
    }

    public function test_remote_verification_rejects_html_before_ingestion(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/verified.json';
        $path = $this->writeSpecification('verified.json', $this->minimalSpecification('v1'));

        Http::fake([
            $sourceUrl => Http::response('<!doctype html><html><body>not a specification</body></html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        try {
            app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);
            $this->fail('An HTML response must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retourne du HTML', $exception->getMessage());
        }

        File::delete($path);

        $this->assertDatabaseCount('documents_api', 0);
        $this->assertDatabaseCount('open_api_specs', 0);
    }

    public function test_remote_verification_rejects_a_json_mismatch_before_ingestion(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/mismatch.json';
        $path = $this->writeSpecification('mismatch.json', $this->minimalSpecification('v1'));
        $mismatched = $this->minimalSpecification('different-version');
        Http::fake([
            $sourceUrl => Http::response(json_encode($mismatched, JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        try {
            app(OpenApiIngestionService::class)->ingestFile($path, 'PVIT', $sourceUrl);
            $this->fail('A remote JSON mismatch must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ne correspond pas', $exception->getMessage());
        } finally {
            File::delete($path);
        }

        $this->assertDatabaseCount('documents_api', 0);
        $this->assertDatabaseCount('open_api_specs', 0);
    }

    public function test_a_metadata_failure_deletes_the_new_document_and_restores_the_previous_active_version(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/atomic.json';
        $path = $this->writeSpecification('atomic.json', $this->minimalSpecification('v1'));
        $this->fakeOfficialFile($sourceUrl, $path);
        $service = app(OpenApiIngestionService::class);

        try {
            $first = $service->ingestFile($path, 'PVIT', $sourceUrl);
            $originalDocumentCount = DocumentApi::query()->count();
            $originalChunkCount = DB::table('chunks')->count();
            $originalEmbeddingCount = DB::table('embeddings')->count();
            File::put($path, json_encode($this->minimalSpecification('v2'), JSON_THROW_ON_ERROR));
            $event = 'eloquent.creating: '.OpenApiSpec::class;
            Event::listen($event, static function (): never {
                throw new RuntimeException('Echec metadata force pour le test.');
            });

            try {
                $service->ingestFile($path, 'PVIT', $sourceUrl);
                $this->fail('The forced metadata failure should escape after compensation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Echec metadata force pour le test.', $exception->getMessage());
            } finally {
                Event::forget($event);
            }

            $this->assertSame($originalDocumentCount, DocumentApi::query()->count());
            $this->assertSame($originalChunkCount, DB::table('chunks')->count());
            $this->assertSame($originalEmbeddingCount, DB::table('embeddings')->count());
            $this->assertTrue(DocumentApi::query()->findOrFail($first['document_id'])->actif);
            $this->assertTrue(OpenApiSpec::query()->findOrFail($first['open_api_spec_id'])->actif);
            $this->assertDatabaseCount('open_api_specs', 1);
        } finally {
            File::delete($path);
        }
    }

    public function test_compensation_does_not_reactivate_an_old_document_over_a_concurrent_publication(): void
    {
        $sourceUrl = 'https://docs.mypvit.pro/openapi/concurrent.json';
        $path = $this->writeSpecification('concurrent.json', $this->minimalSpecification('v1'));
        $this->fakeOfficialFile($sourceUrl, $path);
        $service = app(OpenApiIngestionService::class);

        try {
            $first = $service->ingestFile($path, 'PVIT', $sourceUrl);
            File::put($path, json_encode($this->minimalSpecification('v2'), JSON_THROW_ON_ERROR));
            $event = 'eloquent.retrieved: '.DocumentApi::class;
            $publishedDocumentId = null;
            $triggered = false;

            Event::listen($event, function (DocumentApi $document) use (&$publishedDocumentId, &$triggered, $sourceUrl): void {
                if ($triggered || $document->version !== 'v2') {
                    return;
                }

                $triggered = true;
                $document->update(['actif' => false]);
                $publishedDocumentId = DocumentApi::query()->create([
                    'moyen_paiement_id' => $document->moyen_paiement_id,
                    'titre' => 'Publication OpenAPI concurrente',
                    'version' => 'v3',
                    'lien_officiel' => $sourceUrl,
                    'checksum' => str_repeat('a', 64),
                    'langue' => 'fr',
                    'actif' => true,
                    'has_known_conflicts' => false,
                    'fetched_at' => now(),
                    'link_verified_at' => now(),
                    'date_indexation' => now(),
                ])->id;
            });

            try {
                $service->ingestFile($path, 'PVIT', $sourceUrl);
                $this->fail('The superseded publication must fail and compensate.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('reste inactive', $exception->getMessage());
            } finally {
                Event::forget($event);
            }

            $this->assertNotNull($publishedDocumentId);
            $this->assertTrue(DocumentApi::query()->findOrFail($publishedDocumentId)->actif);
            $this->assertFalse(DocumentApi::query()->findOrFail($first['document_id'])->actif);
            $this->assertDatabaseMissing('documents_api', ['version' => 'v2']);
        } finally {
            File::delete($path);
        }
    }

    public function test_openapi_ingestion_requires_the_shared_pvit_documentation_corpus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Utilisez PVIT');

        app(OpenApiIngestionService::class)->ingestFile(
            storage_path('framework/testing/openapi/not-needed.json'),
            'Airtel Money',
            'https://docs.mypvit.pro/openapi.json',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalSpecification(string $version): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'API Paiements PVIT',
                'version' => $version,
            ],
            'paths' => [
                '/payments' => [
                    'post' => [
                        'summary' => 'Initier un paiement.',
                        'responses' => [
                            '202' => ['description' => 'La demande de paiement est acceptee.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function writeSpecification(string $filename, array $specification): string
    {
        $directory = storage_path('framework/testing/openapi');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        File::put($path, json_encode($specification, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function fakeOfficialFile(string $sourceUrl, string $path): void
    {
        Http::fake(fn () => Http::response(File::get($path), 200, [
            'Content-Type' => 'application/json',
        ]));
    }
}
