<?php

namespace App\Services\Llm\Exceptions;

class LlmUnavailableException extends LlmClientException
{
    // A transient network, rate-limit, or upstream availability failure.
}
