<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('chunks', 'section_slug')) {
                $table->string('section_slug', 180)->nullable()->after('section');
            }

            if (! Schema::hasColumn('chunks', 'content_type')) {
                $table->string('content_type', 60)->nullable()->after('section_slug');
            }

            if (! Schema::hasColumn('chunks', 'search_text')) {
                $table->longText('search_text')->nullable()->after('contenu');
            }

            if (! Schema::hasColumn('chunks', 'index_terms')) {
                $table->json('index_terms')->nullable()->after('metadata');
            }
        });

        Schema::table('chunks', function (Blueprint $table): void {
            $table->index(['status', 'content_type'], 'chunks_status_content_type_idx');
            $table->index('section_slug', 'chunks_section_slug_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX IF NOT EXISTS chunks_search_text_fts ON chunks USING gin (to_tsvector('french', coalesce(search_text, contenu)))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS chunks_search_text_fts');
        }

        Schema::table('chunks', function (Blueprint $table): void {
            $table->dropIndex('chunks_section_slug_idx');
            $table->dropIndex('chunks_status_content_type_idx');
            $table->dropColumn(['section_slug', 'content_type', 'search_text', 'index_terms']);
        });
    }
};
