<?php

namespace App\Http\Middleware;

use App\Services\Assistant\ConversationActions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RespectSupportPreference
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $actions = new ConversationActions;
        if ($response instanceof JsonResponse && $actions->declinesSupport((string) $request->input('question', ''))) {
            $data = $response->getData(true);
            foreach (['answer', 'error'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $data[$key] = $actions->withoutSupportInvitation($data[$key]);
                }
            }
            $data['should_escalate'] = false;
            unset($data['ticket_token']);
            $response->setData($data);
        }

        return $response;
    }
}
