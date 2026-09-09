<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'documents_knowledge_version_source_unique';

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX documents_knowledge_version_source_unique
                ON documents_api (knowledge_version_id, lien_officiel)
                WHERE knowledge_version_id IS NOT NULL AND lien_officiel IS NOT NULL
                SQL);

            return;
        }

        Schema::table('documents_api', function (Blueprint $table): void {
            $table->unique(['knowledge_version_id', 'lien_officiel'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);

            return;
        }

        Schema::table('documents_api', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
