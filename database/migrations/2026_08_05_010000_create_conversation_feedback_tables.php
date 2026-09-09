<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('developpeur_id')
                ->nullable()
                ->constrained('developpeurs_partenaires')
                ->nullOnDelete();
            $table->string('reference', 64)->nullable()->unique();
            $table->string('titre')->nullable();
            $table->json('contexte')->nullable();
            $table->string('langue', 10)->default('fr');
            $table->string('statut', 30)->default('active');
            $table->timestamp('date_cloture')->nullable();
            $table->timestamps();

            $table->index(['developpeur_id', 'statut'], 'conversations_developer_status_idx');
            $table->index(['statut', 'updated_at'], 'conversations_status_updated_idx');
        });

        Schema::create('feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reponse_id')->constrained('reponses')->restrictOnDelete();
            $table->foreignId('developpeur_id')
                ->nullable()
                ->constrained('developpeurs_partenaires')
                ->nullOnDelete();
            $table->string('evaluation', 40);
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->index(['reponse_id', 'evaluation'], 'feedbacks_response_rating_idx');
            $table->index(['developpeur_id', 'created_at'], 'feedbacks_developer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
        Schema::dropIfExists('conversations');
    }
};
