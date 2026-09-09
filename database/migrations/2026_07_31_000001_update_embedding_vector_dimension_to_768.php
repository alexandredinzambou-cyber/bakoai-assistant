<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('embeddings')) {
            return;
        }

        if ($this->currentVectorType() === 'vector(768)') {
            return;
        }

        if (DB::table('embeddings')->whereNotNull('vecteur')->exists()) {
            throw new RuntimeException(
                'La dimension des embeddings ne peut pas etre modifiee avec des vecteurs existants. '
                .'Reindexez explicitement le corpus avant de relancer la migration.',
            );
        }

        DB::statement('ALTER TABLE embeddings ALTER COLUMN vecteur TYPE vector(768)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('embeddings')) {
            return;
        }

        if ($this->currentVectorType() === 'vector(384)') {
            return;
        }

        if (DB::table('embeddings')->whereNotNull('vecteur')->exists()) {
            throw new RuntimeException('Le rollback de dimension exige un index d embeddings vide.');
        }

        DB::statement('ALTER TABLE embeddings ALTER COLUMN vecteur TYPE vector(384)');
    }

    private function currentVectorType(): string
    {
        return strtolower((string) DB::scalar(<<<'SQL'
            SELECT format_type(atttypid, atttypmod)
            FROM pg_attribute
            WHERE attrelid = 'embeddings'::regclass
              AND attname = 'vecteur'
              AND NOT attisdropped
            SQL));
    }
};
