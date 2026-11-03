<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConversationMemoryStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $request->attributes->get('conversation_memory_status');
        if ($response instanceof JsonResponse && $status !== null) {
            $data = $response->getData(true);
            $data['memory_status'] = $status;
            $data['memory_persisted'] = $status === 'durable';
            if ($status !== 'durable') {
                $warning = $status === 'temporary' ? 'La mémoire n’a pas pu être enregistrée durablement. Le suivi est temporaire ; il peut être perdu après une interruption.'
                    : 'La mémoire de conversation est indisponible. Rappelez le contexte complet lors de votre prochaine question.';
                $data['memory_warning'] = $warning;
                if (isset($data['answer'])) {
                    $data['answer'] .= "\n\n".$warning;
                }
            }
            $response->setData($data);
        }

        return $response;
    }
}
