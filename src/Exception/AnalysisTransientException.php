<?php

namespace App\Exception;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

class AnalysisTransientException extends RecoverableMessageHandlingException
{
    public function __construct(
        string $message,
        private readonly string $safeMessage,
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $retryDelay = null
    ) {
        parent::__construct($message, $code, $previous, $retryDelay);
    }

    public function getSafeMessage(): string
    {
        return $this->safeMessage;
    }
}
