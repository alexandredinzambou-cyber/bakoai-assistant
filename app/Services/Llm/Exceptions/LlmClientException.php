<?php

namespace App\Services\Llm\Exceptions;

use RuntimeException;

class LlmClientException extends RuntimeException
{
    // Marker base class for safe errors exposed by an LLM transport client.
}
