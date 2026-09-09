<?php

namespace App\Exceptions;

use RuntimeException;

final class KnowledgeVersionTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        private readonly string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }

    public function publicMessage(): string
    {
        return $this->safeMessage;
    }
}
