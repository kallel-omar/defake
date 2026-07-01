<?php

namespace App\Service;

final class AnalysisAvailabilityService
{
    private const AI_UNAVAILABLE_MESSAGE = 'AI analysis is temporarily unavailable. Please try again later.';
    private const FACEBOOK_UNAVAILABLE_MESSAGE = 'Facebook URL checks are temporarily unavailable. Please try again later.';
    private const FACEBOOK_UNAVAILABLE_DUE_TO_AI_MESSAGE = 'Facebook URL checks are temporarily unavailable because AI analysis is unavailable. Please try again later.';

    public function __construct(
        private readonly string $serperApiKey,
        private readonly string $apifyApiToken,
        private readonly string $groqApiKey,
        private readonly string $groqModel,
    ) {
    }

    public function isAiAnalysisAvailable(): bool
    {
        return $this->hasValue($this->groqApiKey)
            && $this->hasValue($this->groqModel)
            && $this->hasValue($this->serperApiKey);
    }

    public function isFacebookAnalysisAvailable(): bool
    {
        return $this->isAiAnalysisAvailable()
            && $this->hasValue($this->apifyApiToken);
    }

    public function getAiUnavailableMessage(): string
    {
        return self::AI_UNAVAILABLE_MESSAGE;
    }

    public function getFacebookUnavailableMessage(): string
    {
        if (!$this->isAiAnalysisAvailable()) {
            return self::FACEBOOK_UNAVAILABLE_DUE_TO_AI_MESSAGE;
        }

        return self::FACEBOOK_UNAVAILABLE_MESSAGE;
    }

    private function hasValue(string $value): bool
    {
        return trim($value) !== '';
    }
}
