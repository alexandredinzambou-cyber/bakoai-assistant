<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\Exceptions\InvalidLlmResponseException;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EleventhAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    private function sources(): void
    {
        $texts = [
            ['Webhook de notification de statut', "## Notification reçue\n```json\n{\"transactionId\":\"A\",\"status\":\"SUCCESS\",\"amount\":150,\"code\":200}\n```\n## Accusé de réception attendu de votre serveur\nRépondez HTTP 200 avec un écho dynamique du transactionId et du code reçus.\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```"],
            ['Webhook de reporting', "## Corps de la notification\n```json\n{\"event\":\"export.ready\",\"job_id\":\"J1\",\"status\":\"SUCCESS\"}\n```"],
            ['Webhook de reporting', "## Ce que votre endpoint doit répondre\nRépondez **2xx** dès que la notification est enregistrée, sans attendre d’avoir téléchargé le fichier.\n```json\n{\"received\":true}\n```"],
            ['Obtenir les frais', "Endpoint : GET /{code-url-fees}/get-fees\n## Paramètres de requête\n- accountOperationCode (Obligatoire) — Compte marchand.\n- transactionType (Obligatoire) — Type.\n- amount (Obligatoire) — Montant.\n- operatorCode (Obligatoire) — Opérateur."],
        ];
        $chunks = collect($texts)->map(static fn ($entry, $i) => (object) ['chunk_id' => 910 + $i, 'document_titre' => $entry[0], 'section' => $entry[0], 'contenu' => $entry[1], 'lien_officiel' => match ($entry[0]) {
            'Webhook de reporting' => 'https://docs.mypvit.pro/fr/webhooks/reports',
            'Obtenir les frais' => 'https://docs.mypvit.pro/fr/api/fees',
            default => 'https://docs.mypvit.pro/fr/webhooks/status',
        }]);
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->andReturn($chunks);
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_payment_reporting_and_return_survive_database_reload(): void
    {
        $this->sources();
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Quel JSON pour accuser réception du callback de paiement ?'])->assertOk();
        $reference = $first->json('conversation_id');
        foreach ([
            ['Liste les noms des champs de la notification entrante.', 'amount', 'responseCode'],
            ['Je ne veux pas les champs de l’accusé de réception. Donne les noms des champs de la notification entrante.', 'amount', 'responseCode'],
            ['Autre sujet : liste les noms des champs de la notification de reporting d’un export.', 'job_id', 'transactionId'],
            ['Quels champs contient sa notification ?', 'job_id', 'transactionId'],
            ['Quels champs mon serveur doit-il renvoyer pour accuser réception de cette notification de reporting ?', 'received', 'responseCode'],
            ['Revenons au callback de paiement : donne les noms des champs de l’accusé de réception.', 'responseCode', 'job_id'],
        ] as [$question,$present,$absent]) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk()->assertJsonPath('conversation_id', $reference);
            $this->assertStringContainsString($present, $response->json('answer'));
            $this->assertStringNotContainsString($absent, $response->json('answer'));
        }
    }

    public function test_short_fee_questions_keep_only_the_fee_contract(): void
    {
        $this->sources();
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Donne la méthode HTTP et le chemin pour consulter les frais.'])->assertOk();
        $reference = $first->json('conversation_id');
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Quels paramètres sont obligatoires ?', 'conversation_id' => $reference])->assertOk()->assertSee('accountOperationCode');
        $optional = $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels, uniquement si la documentation en indique ?', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('ne listent pas de paramètre optionnel', $optional->json('answer'));
    }

    public function test_truncated_generation_preserves_previous_durable_context(): void
    {
        $this->sources();
        $conversation = Conversation::create(['reference' => 'conv_eleventh_truncated', 'statut' => 'active', 'contexte' => ['topic_question' => 'Frais', 'last_question' => 'Frais', 'last_answer' => 'GET /fees']]);
        $before = $conversation->contexte;
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldReceive('completeWithProvider')->once()->andThrow(new InvalidLlmResponseException('La réponse générée est incomplète.'));
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Explique les notifications de livraison.', 'conversation_id' => $conversation->reference])->assertStatus(502);
        $this->assertStringContainsString('incomplète', $response->json('error'));
        $this->assertSame($before, $conversation->fresh()->contexte);
    }
}
