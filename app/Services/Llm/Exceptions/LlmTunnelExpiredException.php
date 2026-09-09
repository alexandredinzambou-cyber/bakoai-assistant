<?php

namespace App\Services\Llm\Exceptions;

class LlmTunnelExpiredException extends LlmClientException
{
    // The configured public tunnel no longer routes to the upstream service.
}
