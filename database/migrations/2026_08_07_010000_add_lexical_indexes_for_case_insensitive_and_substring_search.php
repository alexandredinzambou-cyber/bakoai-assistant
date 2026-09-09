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

        // RetrievalService filters operation, environment, http_method, endpoint,
        // error_code, content_type and section_slug independently (each is an optional,
        // standalone whereRaw("lower(chunks.{field}) = lower(?)")), and moyens_paiement.nom
        // the same way. Plain btree indexes on the raw columns cannot serve a lower(...)
        // predicate, so add one expression index per column rather than composites: nothing
        // guarantees any two of these fields are ever queried together.
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_operation_ci_idx ON chunks (lower(operation))');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_environment_ci_idx ON chunks (lower(environment))');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_http_method_ci_idx ON chunks (lower(http_method))');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_endpoint_ci_idx ON chunks (lower(endpoint))');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_content_type_ci_idx ON chunks (lower(content_type))');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_section_slug_ci_idx ON chunks (lower(section_slug))');
        DB::statement('CREATE INDEX IF NOT EXISTS moyens_paiement_nom_ci_idx ON moyens_paiement (lower(nom))');

        // error_code is always compared case-insensitively and, when http_status is also
        // filtered, both land in the same query, so a composite keeps that combination
        // sargable while the leading column alone still serves error_code-only filters.
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_error_code_status_ci_idx ON chunks (lower(error_code), http_status)');

        // http_status can also be filtered without error_code (RetrievalService applies it
        // as an independent condition), which the composite above cannot serve on its own.
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_http_status_idx ON chunks (http_status)');

        // AssistantService falls back to ILIKE '%term%' / LIKE '%path%' substring search on
        // chunks.contenu, chunks.section and documents_api.lien_officiel. A leading wildcard
        // defeats any btree index, so those scans are sequential today; pg_trgm lets Postgres
        // use a GIN index for that access pattern instead.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_contenu_trgm_idx ON chunks USING gin (contenu gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS chunks_section_trgm_idx ON chunks USING gin (section gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS documents_api_lien_officiel_trgm_idx ON documents_api USING gin (lien_officiel gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS documents_api_lien_officiel_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_section_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_contenu_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_http_status_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_error_code_status_ci_idx');
        DB::statement('DROP INDEX IF EXISTS moyens_paiement_nom_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_section_slug_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_content_type_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_endpoint_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_http_method_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_environment_ci_idx');
        DB::statement('DROP INDEX IF EXISTS chunks_operation_ci_idx');
    }
};
