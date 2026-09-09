<?php

namespace App\Services\Llm\Exceptions;

class InvalidLlmResponseException extends LlmClientException
{
    // The upstream response did not satisfy the OpenAI-compatible contract.
}
