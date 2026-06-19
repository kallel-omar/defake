<?php

namespace App\Message;

final class AnalyzePostMessage
{
    public function __construct(
        private readonly int $postCheckId
    ) {
    }

    public function getPostCheckId(): int
    {
        return $this->postCheckId;
    }
}