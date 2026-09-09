<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureKnowledgeAdminKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('rag.admin_key');
        $provided = (string) ($request->bearerToken() ?: $request->header('X-BakoAI-Admin-Key'));

        abort_if($expected === '', 503, 'RAG_ADMIN_KEY must be configured before using knowledge administration.');
        abort_unless($provided !== '' && hash_equals($expected, $provided), 403, 'Invalid knowledge administration key.');

        return $next($request);
    }
}
