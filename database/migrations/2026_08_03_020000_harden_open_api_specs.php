<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_api_specs', function (Blueprint $table): void {
            $table->foreignId('document_id')
                ->nullable()
                ->after('moyen_paiement_id')
                ->unique()
                ->constrained('documents_api')
                ->cascadeOnDelete();
            $table->string('source_key', 64)->nullable()->after('fichier_url');
            $table->string('format', 16)->default('json')->after('source_key');
            $table->string('openapi_version', 24)->nullable()->after('format');
            $table->string('checksum', 64)->nullable()->after('version');
            $table->unsignedInteger('paths_count')->default(0)->after('checksum');
            $table->unsignedInteger('operations_count')->default(0)->after('paths_count');
            $table->boolean('actif')->default(true)->after('operations_count');
            $table->timestamp('date_indexation')->nullable()->after('actif');
            $table->index(['moyen_paiement_id', 'actif']);
        });

        $seen = [];

        DB::table('open_api_specs')
            ->select(['id', 'moyen_paiement_id', 'fichier_url'])
            ->orderByDesc('id')
            ->get()
            ->each(function (object $spec) use (&$seen): void {
                $sourceKey = hash('sha256', (string) $spec->fichier_url);
                $identity = ($spec->moyen_paiement_id ?? 'null').'|'.$sourceKey;
                $active = ! isset($seen[$identity]);
                $seen[$identity] = true;

                DB::table('open_api_specs')
                    ->where('id', $spec->id)
                    ->update([
                        'source_key' => $sourceKey,
                        'actif' => $active,
                        'date_indexation' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                    ]);
            });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX open_api_specs_active_source_unique
                ON open_api_specs (moyen_paiement_id, source_key)
                WHERE actif = true AND source_key IS NOT NULL
                SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS open_api_specs_active_source_unique');
        }

        Schema::table('open_api_specs', function (Blueprint $table): void {
            $table->dropIndex(['moyen_paiement_id', 'actif']);
            $table->dropForeign(['document_id']);
            $table->dropUnique(['document_id']);
            $table->dropColumn([
                'document_id',
                'source_key',
                'format',
                'openapi_version',
                'checksum',
                'paths_count',
                'operations_count',
                'actif',
                'date_indexation',
            ]);
        });
    }
};
