<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 80)->unique();
            $table->string('statut', 30)->default('draft');
            $table->unsignedInteger('nombre_documents')->default(0);
            $table->unsignedInteger('nombre_chunks')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('date_activation')->nullable();
            $table->timestamps();

            $table->index(['statut', 'date_activation'], 'knowledge_versions_status_activation_idx');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX knowledge_versions_single_active_unique
                ON knowledge_versions (statut)
                WHERE statut = 'active'
                SQL);
        }

        Schema::create('indexation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_version_id')
                ->nullable()
                ->constrained('knowledge_versions')
                ->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type', 40);
            $table->string('statut', 30)->default('pending');
            $table->decimal('progression', 5, 2)->default(0);
            $table->unsignedInteger('nombre_reussites')->default(0);
            $table->unsignedInteger('nombre_echecs')->default(0);
            $table->json('rapport')->nullable();
            $table->string('correlation_id', 64)->nullable()->unique();
            $table->timestamp('date_debut')->nullable();
            $table->timestamp('date_fin')->nullable();
            $table->timestamps();

            $table->index(['statut', 'created_at'], 'indexation_jobs_status_created_idx');
            $table->index(['knowledge_version_id', 'statut'], 'indexation_jobs_version_status_idx');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('ressource', 120);
            $table->string('ressource_id', 120)->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('date_action')->useCurrent();

            $table->index(['action', 'date_action'], 'audit_logs_action_date_idx');
            $table->index(['ressource', 'ressource_id'], 'audit_logs_resource_idx');
            $table->index('correlation_id', 'audit_logs_correlation_idx');
            $table->index(['user_id', 'date_action'], 'audit_logs_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('indexation_jobs');
        Schema::dropIfExists('knowledge_versions');
    }
};
