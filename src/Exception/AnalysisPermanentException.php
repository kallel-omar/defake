<?php

namespace App\Exception;

class AnalysisPermanentException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $safeMessage,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getSafeMessage(): string
    {
        return $this->safeMessage;
    }
}
