<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // chunks_search_text_fts (added in 2026_08_07_000000) indexes
        // to_tsvector('french', coalesce(search_text, contenu)), which is exactly the
        // expression RetrievalService queries. Postgres cannot reuse chunks_contenu_fts
        // (built on the plain contenu expression, without the coalesce) for that query, so
        // it has been dead weight since chunks_search_text_fts was introduced: every chunk
        // insert/update pays to maintain a second GIN index that no query can ever hit.
        DB::statement('DROP INDEX IF EXISTS chunks_contenu_fts');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX IF NOT EXISTS chunks_contenu_fts ON chunks USING gin (to_tsvector('french', contenu))");
    }
};
