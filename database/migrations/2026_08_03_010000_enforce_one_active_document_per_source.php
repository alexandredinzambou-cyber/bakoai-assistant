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
            $table->timestamp('link_verified_at')->nullable()->after('fetched_at');
        });

        DB::table('documents_api')
            ->where('actif', true)
            ->update([
                // A historical timestamp proves that a file was indexed, not that its official
                // URL was reachable under the strict verifier introduced by this migration.
                // Fail closed and require an explicit reingestion before retrieval.
                'actif' => false,
                'link_verified_at' => null,
            ]);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS documents_active_source_unique
            ON documents_api (moyen_paiement_id, lien_officiel)
            WHERE actif = true AND lien_officiel IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS documents_active_source_unique');
        }

        Schema::table('documents_api', function (Blueprint $table): void {
            $table->dropColumn('link_verified_at');
        });
    }
};
