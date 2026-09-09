<?php

namespace App\Services\Llm;

use App\Services\Llm\Exceptions\LlmClientException;

interface LlmClientInterface
{
    /**
     * @throws LlmClientException When a configured transport fails safely.
     */
    public function complete(string $systemPrompt, string $context, string $question): string;
}
