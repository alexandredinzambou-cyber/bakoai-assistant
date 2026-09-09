<?php

namespace App\Http\Controllers;

use App\Exceptions\KnowledgeVersionTransitionException;
use App\Models\AuditLog;
use App\Models\KnowledgeVersion;
use App\Services\Knowledge\KnowledgeVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class KnowledgeVersionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['draft', 'staging', 'validated', 'active', 'failed'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $versions = KnowledgeVersion::query()
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('statut', $status))
            ->withCount('documents')
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json(['data' => $versions]);
    }

    public function store(Request $request, KnowledgeVersionService $versions): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', 'unique:knowledge_versions,version'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $version = DB::transaction(function () use ($request, $versions, $validated): KnowledgeVersion {
            $version = $versions->createStaging($validated['version'], $validated['metadata'] ?? []);
            $this->audit($request, 'knowledge.version.created', $version);

            return $version;
        });

        return response()->json(['data' => $version], 201);
    }

    public function validateVersion(
        Request $request,
        KnowledgeVersion $knowledgeVersion,
        KnowledgeVersionService $versions,
    ): JsonResponse {
        $validated = $request->validate([
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->transition($request, 'knowledge.version.validated', fn () => $versions->markValidated(
            $knowledgeVersion,
            $validated['metadata'] ?? [],
        ));
    }

    public function activate(
        Request $request,
        KnowledgeVersion $knowledgeVersion,
        KnowledgeVersionService $versions,
    ): JsonResponse {
        return $this->transition(
            $request,
            'knowledge.version.activated',
            fn () => $versions->activate($knowledgeVersion),
        );
    }

    public function rollback(Request $request, KnowledgeVersionService $versions): JsonResponse
    {
        $validated = $request->validate([
            'knowledge_version_id' => ['nullable', 'integer', 'exists:knowledge_versions,id'],
        ]);

        return $this->transition(
            $request,
            'knowledge.version.rolled_back',
            fn () => $versions->rollback($validated['knowledge_version_id'] ?? null),
        );
    }

    private function transition(Request $request, string $action, callable $callback): JsonResponse
    {
        try {
            $version = DB::transaction(function () use ($request, $action, $callback): KnowledgeVersion {
                /** @var KnowledgeVersion $version */
                $version = $callback();
                $this->audit($request, $action, $version);

                return $version;
            });
        } catch (KnowledgeVersionTransitionException $exception) {
            Log::warning('Knowledge version transition rejected.', [
                'action' => $action,
                'reason_code' => $exception->reasonCode,
                'exception' => $exception::class,
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);

            return response()->json([
                'message' => 'La transition de version de connaissance est impossible.',
                'reason_code' => $exception->reasonCode,
            ], 409);
        } catch (Throwable $exception) {
            Log::error('Knowledge version transition failed.', [
                'action' => $action,
                'exception' => $exception::class,
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);

            return response()->json([
                'message' => 'La transition de version de connaissance a echoue.',
            ], 500);
        }

        return response()->json(['data' => $version]);
    }

    private function audit(Request $request, string $action, KnowledgeVersion $version): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'ressource' => 'knowledge_version',
            'ressource_id' => (string) $version->id,
            'details' => ['version' => $version->version, 'status' => $version->statut],
            'ip_address' => $request->ip(),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'date_action' => now(),
        ]);
    }
}
