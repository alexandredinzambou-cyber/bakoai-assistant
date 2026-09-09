<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentApi;
use App\Models\KnowledgeVersion;
use App\Services\Rag\IngestionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class KnowledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = DocumentApi::query()
            ->with('moyenPaiement:id,nom')
            ->with('openApiSpec')
            ->withCount('chunks')
            ->latest('date_indexation')
            ->get()
            ->map(fn (DocumentApi $document): array => [
                'id' => $document->id,
                'titre' => $document->titre,
                'moyen_paiement' => $document->moyenPaiement?->nom,
                'version' => $document->version,
                'lien_officiel' => $document->lien_officiel,
                'chunks' => $document->chunks_count,
                'actif' => $document->actif,
                'has_known_conflicts' => $document->has_known_conflicts,
                'date_indexation' => $document->date_indexation?->toIso8601String(),
                'link_verified_at' => $document->link_verified_at?->toIso8601String(),
                'source_type' => $document->openApiSpec ? 'openapi' : 'documentation',
                'openapi' => $document->openApiSpec ? [
                    'version' => $document->openApiSpec->openapi_version,
                    'api_version' => $document->openApiSpec->version,
                    'paths' => $document->openApiSpec->paths_count,
                    'operations' => $document->openApiSpec->operations_count,
                ] : null,
            ]);

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request, IngestionService $ingestion): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'file', 'max:5120', 'extensions:md,html,htm,txt'],
            'moyen_paiement' => ['required', 'string', Rule::in(config('rag.documentation_corpora', ['PVIT']))],
            'source_url' => ['required', 'url:https', 'max:2048'],
            'version' => ['nullable', 'string', 'max:80'],
            'knowledge_version_id' => [
                'nullable',
                'integer',
                Rule::exists('knowledge_versions', 'id')->where('statut', 'staging'),
            ],
        ]);

        if (($validated['knowledge_version_id'] ?? null) === null
            && KnowledgeVersion::query()->where('statut', 'active')->exists()) {
            throw ValidationException::withMessages([
                'knowledge_version_id' => ['Une version staging est obligatoire lorsqu un corpus versionne est actif.'],
            ]);
        }

        $sourceUrl = $validated['source_url'] ?? null;
        $allowedHosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));
        $sourcePath = '/'.ltrim(rawurldecode((string) parse_url((string) $sourceUrl, PHP_URL_PATH)), '/');
        $allowedPathPrefixes = (array) config('rag.allowed_source_path_prefixes', ['/fr/']);

        if ($sourceUrl !== null && (
            ! in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_HOST)), $allowedHosts, true)
            || ! collect($allowedPathPrefixes)->contains(
                static fn (string $prefix): bool => str_starts_with($sourcePath, rtrim($prefix, '/').'/'),
            )
        )) {
            throw ValidationException::withMessages([
                'source_url' => ['La source doit appartenir au domaine et au chemin documentaire officiels autorises.'],
            ]);
        }

        if ($validated['source']->getSize() > (int) config('rag.max_source_bytes', 5 * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'source' => ['Le fichier depasse la taille maximale configuree.'],
            ]);
        }

        $directory = storage_path('app/sources/uploads');
        File::ensureDirectoryExists($directory);
        $extension = strtolower($validated['source']->getClientOriginalExtension());
        $filename = now()->format('YmdHis').'-'.Str::random(12).'.'.$extension;
        $validated['source']->move($directory, $filename);

        $storedPath = $directory.DIRECTORY_SEPARATOR.$filename;

        try {
            $result = $ingestion->ingestFile(
                $storedPath,
                $validated['moyen_paiement'],
                $sourceUrl,
                $validated['version'] ?? null,
                $validated['knowledge_version_id'] ?? null,
                afterPersist: function (DocumentApi $document, array $result) use ($request): void {
                    AuditLog::create([
                        'user_id' => $request->user()?->getAuthIdentifier(),
                        'action' => 'knowledge.source.imported',
                        'ressource' => 'document_api',
                        'ressource_id' => (string) $document->id,
                        'details' => [
                            'source_url' => $document->lien_officiel,
                            'version' => $document->version,
                            'knowledge_version_id' => $document->knowledge_version_id,
                            'chunks' => $result['chunks'],
                        ],
                        'ip_address' => $request->ip(),
                        'correlation_id' => $request->attributes->get('correlation_id'),
                        'date_action' => now(),
                    ]);
                },
            );
        } catch (QueryException $exception) {
            File::delete($storedPath);

            throw $exception;
        } catch (RuntimeException $exception) {
            File::delete($storedPath);

            throw ValidationException::withMessages([
                'source_url' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            File::delete($storedPath);

            throw $exception;
        }

        return response()->json(['data' => $result], 201);
    }
}
