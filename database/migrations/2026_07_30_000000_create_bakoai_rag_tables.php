<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';

        if ($isPostgres) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('moyens_paiement', function (Blueprint $table): void {
            $table->id();
            $table->string('nom')->unique();
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('documents_api', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('moyen_paiement_id')->nullable()->constrained('moyens_paiement')->nullOnDelete();
            $table->string('titre');
            $table->string('version')->nullable();
            $table->string('lien_officiel')->nullable();
            $table->timestamp('date_indexation')->nullable();
            $table->timestamps();
        });

        Schema::create('chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents_api')->cascadeOnDelete();
            $table->longText('contenu');
            $table->string('section')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
        });

        Schema::create('embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chunk_id')->unique()->constrained('chunks')->cascadeOnDelete();
            $table->string('modele');
            $table->timestamps();
        });

        Schema::table('embeddings', function (Blueprint $table) use ($isPostgres): void {
            if ($isPostgres) {
                $table->vector('vecteur', (int) config('rag.embedding_dimensions', 768))->nullable();
            } else {
                // SQLite is used only by the automated test suite. Production remains PostgreSQL.
                $table->json('vecteur')->nullable();
            }
        });

        Schema::create('open_api_specs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('moyen_paiement_id')->nullable()->constrained('moyens_paiement')->nullOnDelete();
            $table->string('fichier_url');
            $table->string('version')->nullable();
            $table->timestamps();
        });

        Schema::create('developpeurs_partenaires', function (Blueprint $table): void {
            $table->id();
            $table->string('nom');
            $table->string('entreprise')->nullable();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('developpeur_id')->nullable()->constrained('developpeurs_partenaires')->nullOnDelete();
            $table->longText('texte');
            $table->timestamps();
        });

        Schema::create('reponses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->longText('texte_explicatif');
            $table->json('liens_associes')->nullable();
            $table->timestamps();
        });

        Schema::create('chunk_reponse', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chunk_id')->constrained('chunks')->cascadeOnDelete();
            $table->foreignId('reponse_id')->constrained('reponses')->cascadeOnDelete();
            $table->unique(['chunk_id', 'reponse_id']);
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->unique()->constrained('questions')->cascadeOnDelete();
            $table->string('statut')->default('ouvert');
            $table->timestamp('date_creation');
            $table->timestamps();
        });

        Schema::create('responsables_techniques', function (Blueprint $table): void {
            $table->id();
            $table->string('nom');
            $table->string('fonction')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsables_techniques');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('chunk_reponse');
        Schema::dropIfExists('reponses');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('developpeurs_partenaires');
        Schema::dropIfExists('open_api_specs');
        Schema::dropIfExists('embeddings');
        Schema::dropIfExists('chunks');
        Schema::dropIfExists('documents_api');
        Schema::dropIfExists('moyens_paiement');
    }
};
