<?php

namespace App\Tests\Service;

use App\Service\AnalysisAvailabilityService;
use PHPUnit\Framework\TestCase;

class AnalysisAvailabilityServiceTest extends TestCase
{
    public function testAllRequiredKeysEnableAiAndFacebookAnalysis(): void
    {
        $availability = new AnalysisAvailabilityService(
            serperApiKey: 'serper',
            apifyApiToken: 'apify',
            groqApiKey: 'groq',
            groqModel: 'model',
        );

        self::assertTrue($availability->isAiAnalysisAvailable());
        self::assertTrue($availability->isFacebookAnalysisAvailable());
    }

    public function testMissingAiKeyDisablesAiAndFacebookAnalysis(): void
    {
        $availability = new AnalysisAvailabilityService(
            serperApiKey: 'serper',
            apifyApiToken: 'apify',
            groqApiKey: '',
            groqModel: 'model',
        );

        self::assertFalse($availability->isAiAnalysisAvailable());
        self::assertFalse($availability->isFacebookAnalysisAvailable());
        self::assertSame(
            'Facebook URL checks are temporarily unavailable because AI analysis is unavailable. Please try again later.',
            $availability->getFacebookUnavailableMessage()
        );
    }

    public function testMissingEvidenceSearchKeyDisablesAiAnalysis(): void
    {
        $availability = new AnalysisAvailabilityService(
            serperApiKey: ' ',
            apifyApiToken: 'apify',
            groqApiKey: 'groq',
            groqModel: 'model',
        );

        self::assertFalse($availability->isAiAnalysisAvailable());
        self::assertFalse($availability->isFacebookAnalysisAvailable());
    }

    public function testMissingFacebookExtractorKeyOnlyDisablesFacebookAnalysis(): void
    {
        $availability = new AnalysisAvailabilityService(
            serperApiKey: 'serper',
            apifyApiToken: '',
            groqApiKey: 'groq',
            groqModel: 'model',
        );

        self::assertTrue($availability->isAiAnalysisAvailable());
        self::assertFalse($availability->isFacebookAnalysisAvailable());
        self::assertSame(
            'Facebook URL checks are temporarily unavailable. Please try again later.',
            $availability->getFacebookUnavailableMessage()
        );
    }
}
