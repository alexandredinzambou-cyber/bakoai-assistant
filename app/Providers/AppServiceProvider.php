<?php

namespace App\Providers;

use App\Services\Llm\FallbackLlmClient;
use App\Services\Llm\LlmClientInterface;
use Illuminate\Support\ServiceProvider;
use Pgvector\Laravel\Schema as PgvectorSchema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClientInterface::class, FallbackLlmClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register pgvector's Blueprint macro without loading its unconditional PostgreSQL-only
        // vendor migration; our application migration handles PostgreSQL and SQLite test runs.
        PgvectorSchema::register();
    }
}
