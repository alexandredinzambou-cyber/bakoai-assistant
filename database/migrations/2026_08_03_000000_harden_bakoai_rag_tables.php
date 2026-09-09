<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_api', function (Blueprint $table): void {
            $table->string('checksum', 64)->nullable()->after('lien_officiel');
            $table->string('langue', 10)->default('fr')->after('checksum');
            $table->boolean('actif')->default(true)->after('langue');
            $table->boolean('has_known_conflicts')->default(false)->after('actif');
            $table->text('conflict_note')->nullable()->after('has_known_conflicts');
            $table->timestamp('fetched_at')->nullable()->after('conflict_note');
            $table->index(['moyen_paiement_id', 'actif']);
        });

        Schema::table('reponses', function (Blueprint $table): void {
            $table->string('statut', 40)->default('answered')->after('texte_explicatif');
            $table->string('guard_reason', 80)->nullable()->after('statut');
            $table->decimal('confidence', 5, 4)->nullable()->after('guard_reason');
            $table->string('prompt_version', 30)->nullable()->after('confidence');
        });

        DB::table('documents_api')
            ->where('lien_officiel', 'https://docs.mypvit.pro/fr/v2/api/renew-secret')
            ->update([
                'version' => DB::raw("coalesce(version, 'v2')"),
                'has_known_conflicts' => true,
                'conflict_note' => 'La page officielle v2 se contredit sur le nom et le transport des parametres, X-Secret et la livraison synchrone ou asynchrone du secret.',
            ]);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS embeddings_chunk_unique ON embeddings (chunk_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS embeddings_modele_index ON embeddings (modele)');
        DB::statement('CREATE INDEX IF NOT EXISTS embeddings_vecteur_hnsw ON embeddings USING hnsw (vecteur vector_cosine_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS chunks_contenu_fts ON chunks USING gin (to_tsvector('french', contenu))");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tickets_question_unique ON tickets (question_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS chunks_contenu_fts');
            DB::statement('DROP INDEX IF EXISTS embeddings_vecteur_hnsw');
            DB::statement('DROP INDEX IF EXISTS embeddings_modele_index');
            DB::statement('DROP INDEX IF EXISTS embeddings_chunk_unique');
            DB::statement('DROP INDEX IF EXISTS tickets_question_unique');
        }

        Schema::table('reponses', function (Blueprint $table): void {
            $table->dropColumn(['statut', 'guard_reason', 'confidence', 'prompt_version']);
        });

        Schema::table('documents_api', function (Blueprint $table): void {
            $table->dropIndex(['moyen_paiement_id', 'actif']);
            $table->dropColumn(['checksum', 'langue', 'actif', 'has_known_conflicts', 'conflict_note', 'fetched_at']);
        });
    }
};
