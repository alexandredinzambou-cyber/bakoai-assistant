<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_api', function (Blueprint $table): void {
            $table->foreignId('knowledge_version_id')
                ->nullable()
                ->after('moyen_paiement_id')
                ->constrained('knowledge_versions')
                ->restrictOnDelete();
            $table->json('metadata')->nullable()->after('conflict_note');
            $table->timestamp('last_modified_at')->nullable()->after('fetched_at');

            $table->index(['knowledge_version_id', 'actif'], 'documents_api_version_active_idx');
            $table->index('last_modified_at', 'documents_api_last_modified_idx');
        });

        Schema::table('chunks', function (Blueprint $table): void {
            $table->unsignedInteger('token_count')->nullable()->after('position');
            $table->string('payment_method', 80)->nullable()->after('token_count');
            $table->string('operation', 120)->nullable()->after('payment_method');
            $table->string('environment', 40)->nullable()->after('operation');
            $table->string('http_method', 12)->nullable()->after('environment');
            $table->string('endpoint', 500)->nullable()->after('http_method');
            $table->string('error_code', 120)->nullable()->after('endpoint');
            $table->unsignedSmallInteger('http_status')->nullable()->after('error_code');
            $table->string('status', 30)->default('active')->after('http_status');
            $table->json('metadata')->nullable()->after('status');

            $table->index(['document_id', 'position'], 'chunks_document_position_idx');
            $table->index(['status', 'payment_method'], 'chunks_status_payment_idx');
            $table->index(['operation', 'environment'], 'chunks_operation_environment_idx');
            $table->index(['http_method', 'endpoint'], 'chunks_method_endpoint_idx');
            $table->index(['error_code', 'http_status'], 'chunks_error_status_idx');
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('developpeur_id')
                ->constrained('conversations')
                ->restrictOnDelete();
            $table->longText('clean_text')->nullable()->after('texte');
            $table->string('intent', 100)->nullable()->after('clean_text');
            $table->string('detected_payment_method', 80)->nullable()->after('intent');
            $table->json('metadata')->nullable()->after('detected_payment_method');

            $table->index('developpeur_id', 'questions_developer_idx');
            $table->index(['conversation_id', 'created_at'], 'questions_conversation_created_idx');
            $table->index(['detected_payment_method', 'intent'], 'questions_scope_intent_idx');
        });

        Schema::table('reponses', function (Blueprint $table): void {
            $table->string('confidence_level', 20)->nullable()->after('confidence');
            $table->unsignedInteger('duration_ms')->nullable()->after('confidence_level');
            $table->string('error_code', 80)->nullable()->after('duration_ms');
            $table->json('metadata')->nullable()->after('liens_associes');

            $table->unique('question_id', 'reponses_question_unique');
            $table->index(['statut', 'confidence_level'], 'reponses_status_confidence_idx');
            $table->index('error_code', 'reponses_error_code_idx');
        });

        Schema::table('chunk_reponse', function (Blueprint $table): void {
            $table->index('reponse_id', 'chunk_reponse_response_idx');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('developpeur_id')
                ->nullable()
                ->after('question_id')
                ->constrained('developpeurs_partenaires')
                ->nullOnDelete();
            $table->string('reference', 64)->nullable()->after('developpeur_id')->unique();
            $table->text('resume')->nullable()->after('reference');
            $table->string('payment_method', 80)->nullable()->after('resume');
            $table->string('operation', 120)->nullable()->after('payment_method');
            $table->string('environment', 40)->nullable()->after('operation');
            $table->string('error_code', 120)->nullable()->after('environment');
            $table->json('verifications_proposees')->nullable()->after('error_code');
            $table->json('documents_consultes')->nullable()->after('verifications_proposees');
            $table->json('messages_utiles')->nullable()->after('documents_consultes');
            $table->string('priorite', 20)->default('normale')->after('messages_utiles');
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->after('priorite')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('date_resolution')->nullable()->after('date_creation');
            $table->json('metadata')->nullable()->after('date_resolution');

            $table->index(['statut', 'priorite'], 'tickets_status_priority_idx');
            $table->index(['developpeur_id', 'statut'], 'tickets_developer_status_idx');
            $table->index(['assigned_to_user_id', 'statut'], 'tickets_assignee_status_idx');
            $table->index('error_code', 'tickets_error_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_error_code_idx');
            $table->dropIndex('tickets_assignee_status_idx');
            $table->dropIndex('tickets_developer_status_idx');
            $table->dropIndex('tickets_status_priority_idx');
            $table->dropUnique('tickets_reference_unique');
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropForeign(['developpeur_id']);
            $table->dropColumn([
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
            ]);
        });

        Schema::table('chunk_reponse', function (Blueprint $table): void {
            $table->dropIndex('chunk_reponse_response_idx');
        });

        Schema::table('reponses', function (Blueprint $table): void {
            $table->dropIndex('reponses_error_code_idx');
            $table->dropIndex('reponses_status_confidence_idx');
            $table->dropUnique('reponses_question_unique');
            $table->dropColumn(['confidence_level', 'duration_ms', 'error_code', 'metadata']);
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->dropIndex('questions_scope_intent_idx');
            $table->dropIndex('questions_conversation_created_idx');
            $table->dropIndex('questions_developer_idx');
            $table->dropForeign(['conversation_id']);
            $table->dropColumn(['conversation_id', 'clean_text', 'intent', 'detected_payment_method', 'metadata']);
        });

        Schema::table('chunks', function (Blueprint $table): void {
            $table->dropIndex('chunks_error_status_idx');
            $table->dropIndex('chunks_method_endpoint_idx');
            $table->dropIndex('chunks_operation_environment_idx');
            $table->dropIndex('chunks_status_payment_idx');
            $table->dropIndex('chunks_document_position_idx');
            $table->dropColumn([
                'token_count',
                'payment_method',
                'operation',
                'environment',
                'http_method',
                'endpoint',
                'error_code',
                'http_status',
                'status',
                'metadata',
            ]);
        });

        Schema::table('documents_api', function (Blueprint $table): void {
            $table->dropIndex('documents_api_last_modified_idx');
            $table->dropIndex('documents_api_version_active_idx');
            $table->dropForeign(['knowledge_version_id']);
            $table->dropColumn(['knowledge_version_id', 'metadata', 'last_modified_at']);
        });
    }
};
