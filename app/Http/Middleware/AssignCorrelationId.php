<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = trim((string) $request->header('X-Correlation-ID'));
        $correlationId = preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/', $provided) === 1
            ? $provided
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
