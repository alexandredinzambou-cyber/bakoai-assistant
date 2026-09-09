<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\DeveloppeurPartenaire;
use App\Models\DocumentApi;
use App\Models\Feedback;
use App\Models\IndexationJob;
use App\Models\KnowledgeVersion;
use App\Models\MoyenPaiement;
use App\Models\Question;
use App\Models\Reponse;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KnowledgeDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_governance_and_traceability_schema_is_available(): void
    {
        foreach (['knowledge_versions', 'indexation_jobs', 'conversations', 'feedbacks', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }

        $this->assertTrue(Schema::hasColumns('documents_api', [
            'knowledge_version_id',
            'metadata',
            'last_modified_at',
        ]));
        $this->assertTrue(Schema::hasColumns('chunks', [
            'search_text',
            'token_count',
            'payment_method',
            'section_slug',
            'content_type',
            'operation',
            'environment',
            'http_method',
            'endpoint',
            'error_code',
            'http_status',
            'status',
            'metadata',
            'index_terms',
        ]));
        $this->assertTrue(Schema::hasColumns('questions', [
            'conversation_id',
            'clean_text',
            'intent',
            'detected_payment_method',
            'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('reponses', [
            'confidence_level',
            'duration_ms',
            'error_code',
            'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('tickets', [
            'developpeur_id',
            'reference',
            'resume',
            'payment_method',
            'operation',
            'environment',
            'error_code',
            'verifications_proposees',
            'documents_consultes',
            'messages_utiles',
            'priorite',
            'assigned_to_user_id',
            'date_resolution',
            'metadata',
        ]));

        $chunkIndexes = collect(Schema::getIndexes('chunks'))->pluck('name');
        $this->assertContains('chunks_document_position_idx', $chunkIndexes);
        $this->assertContains('chunks_status_payment_idx', $chunkIndexes);
        $this->assertContains('chunks_method_endpoint_idx', $chunkIndexes);
        $this->assertContains('chunks_error_status_idx', $chunkIndexes);
        $this->assertContains('chunks_status_content_type_idx', $chunkIndexes);
        $this->assertContains('chunks_section_slug_idx', $chunkIndexes);
    }

    public function test_models_expose_traceable_relations_and_casts(): void
    {
        $user = User::factory()->create();
        $developpeur = DeveloppeurPartenaire::create([
            'nom' => 'Partenaire test',
            'entreprise' => 'BAKOAI QA',
        ]);
        $version = KnowledgeVersion::create([
            'version' => 'kb-2026-08-05',
            'statut' => 'active',
            'metadata' => ['source' => 'official'],
            'date_activation' => now(),
        ]);
        $payment = MoyenPaiement::create(['nom' => 'PVIT', 'type' => 'passerelle']);
        $document = DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'knowledge_version_id' => $version->id,
            'titre' => 'Guide de test',
            'lien_officiel' => 'https://docs.mypvit.pro/fr/test/data-model',
            'metadata' => ['origin' => 'crawler'],
            'last_modified_at' => now(),
            'actif' => true,
        ]);
        $chunk = Chunk::create([
            'document_id' => $document->id,
            'contenu' => 'Le callback doit etre confirme selon la documentation officielle.',
            'search_text' => 'callback notification webhook',
            'section' => 'Callback',
            'section_slug' => 'callback',
            'content_type' => 'callback',
            'position' => 1,
            'token_count' => 11,
            'payment_method' => 'PVIT',
            'operation' => 'callback',
            'environment' => 'sandbox',
            'http_method' => 'POST',
            'endpoint' => '/callbacks',
            'error_code' => 'CALLBACK_TIMEOUT',
            'http_status' => 504,
            'metadata' => ['anchor' => 'callback'],
            'index_terms' => ['callback', 'webhook'],
        ]);
        $conversation = Conversation::create([
            'developpeur_id' => $developpeur->id,
            'reference' => 'conversation-data-model',
            'titre' => 'Diagnostic callback',
            'contexte' => ['environment' => 'sandbox'],
        ]);
        $question = Question::create([
            'developpeur_id' => $developpeur->id,
            'conversation_id' => $conversation->id,
            'texte' => 'Pourquoi mon callback expire ?',
            'clean_text' => 'Pourquoi mon callback expire ?',
            'intent' => 'diagnostic',
            'detected_payment_method' => 'PVIT',
            'metadata' => ['sensitive_data_detected' => false],
        ]);
        $reponse = Reponse::create([
            'question_id' => $question->id,
            'texte_explicatif' => 'Diagnostic fonde sur le guide.',
            'statut' => 'answered',
            'confidence' => 0.91,
            'confidence_level' => 'high',
            'duration_ms' => 125,
            'liens_associes' => [$document->lien_officiel],
            'metadata' => ['correlation_id' => 'corr-data-model'],
        ]);
        $reponse->chunks()->attach($chunk->id);
        $feedback = Feedback::create([
            'reponse_id' => $reponse->id,
            'developpeur_id' => $developpeur->id,
            'evaluation' => 'utile',
            'commentaire' => 'Reponse claire.',
        ]);
        $ticket = Ticket::create([
            'question_id' => $question->id,
            'developpeur_id' => $developpeur->id,
            'reference' => 'TICKET-DATA-MODEL',
            'resume' => 'Callback en delai depasse.',
            'payment_method' => 'PVIT',
            'operation' => 'callback',
            'environment' => 'sandbox',
            'error_code' => 'CALLBACK_TIMEOUT',
            'verifications_proposees' => ['Verifier la disponibilite du callback'],
            'documents_consultes' => [$document->lien_officiel],
            'messages_utiles' => [$question->texte],
            'priorite' => 'haute',
            'assigned_to_user_id' => $user->id,
            'statut' => 'ouvert',
            'date_creation' => now(),
            'metadata' => ['correlation_id' => 'corr-data-model'],
        ]);
        $job = IndexationJob::create([
            'knowledge_version_id' => $version->id,
            'initiated_by_user_id' => $user->id,
            'type' => 'incremental',
            'statut' => 'completed',
            'progression' => 100,
            'nombre_reussites' => 1,
            'rapport' => ['documents' => 1],
            'correlation_id' => 'index-data-model',
        ]);
        $audit = AuditLog::create([
            'user_id' => $user->id,
            'action' => 'knowledge.indexed',
            'ressource' => 'knowledge_version',
            'ressource_id' => (string) $version->id,
            'details' => ['job_id' => $job->id],
            'ip_address' => '203.0.113.10',
            'correlation_id' => 'index-data-model',
        ]);

        $this->assertTrue($document->knowledgeVersion->is($version));
        $this->assertTrue($version->documents->first()->is($document));
        $this->assertTrue($question->conversation->is($conversation));
        $this->assertTrue($conversation->questions->first()->is($question));
        $this->assertInstanceOf(HasOne::class, $question->ticket());
        $this->assertTrue($question->ticket->is($ticket));
        $this->assertTrue($question->reponse->is($reponse));
        $this->assertTrue($reponse->feedbacks->first()->is($feedback));
        $this->assertTrue($reponse->chunks->first()->is($chunk));
        $this->assertTrue($job->knowledgeVersion->is($version));
        $this->assertTrue($job->initiatedBy->is($user));
        $this->assertTrue($audit->user->is($user));
        $this->assertTrue($ticket->assignedTo->is($user));
        $this->assertSame(['origin' => 'crawler'], $document->metadata);
        $this->assertSame(['anchor' => 'callback'], $chunk->metadata);
        $this->assertSame(['callback', 'webhook'], $chunk->index_terms);
        $this->assertSame('callback', $chunk->content_type);
        $this->assertSame(11, $chunk->token_count);
        $this->assertSame(504, $chunk->http_status);
        $this->assertSame(125, $reponse->duration_ms);
        $this->assertSame(['documents' => 1], $job->rapport);
    }

    public function test_one_question_cannot_receive_multiple_responses(): void
    {
        $question = Question::create(['texte' => 'Question unique']);
        Reponse::create([
            'question_id' => $question->id,
            'texte_explicatif' => 'Premiere reponse.',
        ]);

        $this->expectException(QueryException::class);

        Reponse::create([
            'question_id' => $question->id,
            'texte_explicatif' => 'Seconde reponse interdite.',
        ]);
    }

    public function test_a_knowledge_version_referenced_by_a_document_cannot_be_deleted(): void
    {
        $version = KnowledgeVersion::create(['version' => 'kb-preserved']);
        $payment = MoyenPaiement::create(['nom' => 'PVIT', 'type' => 'passerelle']);
        DocumentApi::create([
            'moyen_paiement_id' => $payment->id,
            'knowledge_version_id' => $version->id,
            'titre' => 'Document historique',
            'lien_officiel' => 'https://docs.mypvit.pro/fr/test/preserved',
        ]);

        $this->expectException(QueryException::class);

        $version->delete();
    }

    public function test_a_response_with_feedback_cannot_be_deleted_silently(): void
    {
        $question = Question::create(['texte' => 'Question avec feedback']);
        $reponse = Reponse::create([
            'question_id' => $question->id,
            'texte_explicatif' => 'Reponse evaluee.',
        ]);
        Feedback::create([
            'reponse_id' => $reponse->id,
            'evaluation' => 'utile',
        ]);

        $this->expectException(QueryException::class);

        $reponse->delete();
    }
}
