<?php

namespace App\Http\Controllers;

use App\Models\DocumentApi;
use App\Models\Question;
use App\Models\Ticket;
use App\Services\Assistant\AssistantService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AssistantController extends Controller
{
    public function ask(Request $request, AssistantService $assistant): JsonResponse
    {
        // A remote LLM call can outlive PHP's default 30-second execution limit. Leave enough
        // time for the configured HTTP timeout so AssistantService can convert failure into a
        // graceful escalation response.
        set_time_limit(120);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:8000'],
            'developpeur_id' => ['prohibited'],
            'moyen_paiement' => ['nullable', 'string', Rule::in(config('rag.payment_methods', []))],
            'operation' => ['nullable', 'string', Rule::in(config('rag.operations', []))],
            'knowledge_version' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'environment' => ['nullable', 'string', Rule::in(['sandbox', 'production'])],
            'http_method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'])],
            'endpoint' => ['nullable', 'string', 'max:500', 'regex:#\A/[A-Za-z0-9_{}.:/~\-]+\z#'],
            'error_code' => ['nullable', 'string', 'max:120', 'regex:/\A[A-Za-z0-9_.-]+\z/'],
            'http_status' => ['nullable', 'integer', 'between:100,599'],
            'create_ticket' => ['nullable', 'boolean'],
            'conversation_id' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ]);

        $result = $assistant->ask(
            $validated['question'],
            null,
            [
                'moyen_paiement' => $validated['moyen_paiement'] ?? null,
                'operation' => $validated['operation'] ?? null,
                'knowledge_version' => $validated['knowledge_version'] ?? null,
                'environment' => $validated['environment'] ?? null,
                'http_method' => $validated['http_method'] ?? null,
                'endpoint' => $validated['endpoint'] ?? null,
                'error_code' => $validated['error_code'] ?? null,
                'http_status' => $validated['http_status'] ?? null,
                'create_ticket' => (bool) ($validated['create_ticket'] ?? false),
                'conversation_id' => $validated['conversation_id'] ?? null,
            ],
        );

        return response()->json($result);
    }

    public function ticket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'ticket_token' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $authorizedQuestionId = (int) Crypt::decryptString($validated['ticket_token']);
        } catch (DecryptException) {
            abort(403, 'Invalid ticket authorization token.');
        }

        abort_unless($authorizedQuestionId === (int) $validated['question_id'], 403, 'Invalid ticket authorization token.');

        $question = Question::findOrFail($validated['question_id']);
        $ticket = Ticket::firstOrCreate(
            ['question_id' => $question->id],
            ['statut' => 'ouvert', 'date_creation' => now()],
        );

        return response()->json([
            'ticket_id' => $ticket->id,
            'statut' => $ticket->statut,
            'question_id' => $question->id,
        ], $ticket->wasRecentlyCreated ? 201 : 200);
    }

    public function status(): JsonResponse
    {
        $database = false;
        $vector = false;
        $documents = 0;

        try {
            DB::selectOne('select 1');
            $database = true;
            $documents = DocumentApi::query()->where('actif', true)->count();
            $vector = DB::getDriverName() !== 'pgsql'
                || (bool) DB::scalar("select exists (select 1 from pg_extension where extname = 'vector')");
        } catch (Throwable) {
            // Readiness is intentionally side-effect free and never spends a remote LLM request.
        }

        $provider = (string) config('llm.default_provider', 'gemini');
        $providerConfig = config("llm.providers.{$provider}", []);
        $llmConfigured = is_array($providerConfig)
            && filled($providerConfig['base_url'] ?? null)
            && filled($providerConfig['api_key'] ?? null)
            && filled($providerConfig['model'] ?? null);
        $embeddingProvider = (string) config('embedding.default_provider', 'nvidia');
        $embeddingModel = (string) config("embedding.providers.{$embeddingProvider}.model", config('rag.embedding_model'));

        return response()->json([
            'connected' => $database && $vector && $llmConfigured && $documents > 0,
            'database' => $database,
            'pgvector' => $vector,
            'llm_configured' => $llmConfigured,
            'provider' => $provider,
            'embedding_provider' => $embeddingProvider,
            'embedding_model' => $embeddingModel,
            'documents' => $documents,
        ]);
    }
}
