<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HomeController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class HomeControllerTest extends TestCase
{
    public function testEmptyContextIsAllowed(): void
    {
        self::assertNull($this->callControllerMethod('normalizeContextCountry', '   '));
        self::assertNull($this->callControllerMethod('normalizeContextTopic', ''));
    }

    public function testValidCountryCodeIsNormalized(): void
    {
        self::assertSame('TN', $this->callControllerMethod('normalizeContextCountry', ' tn '));
        self::assertSame('GLOBAL', $this->callControllerMethod('normalizeContextCountry', 'global'));
        self::assertSame('US', $this->callControllerMethod('normalizeContextCountry', 'us'));
        self::assertSame('QA', $this->callControllerMethod('normalizeContextCountry', 'qa'));
    }

    public function testInvalidCountryIsIgnoredSafely(): void
    {
        self::assertNull($this->callControllerMethod('normalizeContextCountry', 'Tunisia'));
        self::assertNull($this->callControllerMethod('normalizeContextCountry', 'XX'));
        self::assertNull($this->callControllerMethod('normalizeContextCountry', 'OTHER'));
    }

    public function testCountryOptionsUseGlobalAndIsoCodesWithoutOther(): void
    {
        $countryOptions = $this->callControllerMethod('getManualContextCountryOptions');

        self::assertSame('Global / Not country-specific', $countryOptions['GLOBAL'] ?? null);
        self::assertSame('Tunisia', $countryOptions['TN'] ?? null);
        self::assertSame('United States', $countryOptions['US'] ?? null);
        self::assertSame('Qatar', $countryOptions['QA'] ?? null);
        self::assertArrayNotHasKey('OTHER', $countryOptions);
    }

    public function testInvalidTopicIsIgnoredSafely(): void
    {
        self::assertNull($this->callControllerMethod('normalizeContextTopic', 'not-a-real-topic'));
    }

    public function testValidTopicIsNormalized(): void
    {
        self::assertSame('sports', $this->callControllerMethod('normalizeContextTopic', ' Sports '));
        self::assertSame('technology', $this->callControllerMethod('normalizeContextTopic', 'technology'));
    }

    public function testSameTextWithDifferentContextCreatesDifferentHashCheck(): void
    {
        $tunisiaUrl = $this->callControllerMethod(
            'buildManualTextInternalUrl',
            'The player signed for two years.',
            'TN',
            'sports'
        );
        $franceUrl = $this->callControllerMethod(
            'buildManualTextInternalUrl',
            'The player signed for two years.',
            'US',
            'sports'
        );

        self::assertNotSame($tunisiaUrl, $franceUrl);
        self::assertNotSame(hash('sha256', $tunisiaUrl), hash('sha256', $franceUrl));
    }

    public function testSameTextWithSameContextStillDeduplicates(): void
    {
        $firstUrl = $this->callControllerMethod(
            'buildManualTextInternalUrl',
            'The player signed for two years.',
            'TN',
            'sports'
        );
        $secondUrl = $this->callControllerMethod(
            'buildManualTextInternalUrl',
            'The player signed for two years.',
            'TN',
            'sports'
        );

        self::assertSame($firstUrl, $secondUrl);
        self::assertSame(hash('sha256', $firstUrl), hash('sha256', $secondUrl));
    }

    public function testEmptyContextKeepsLegacyManualTextHash(): void
    {
        $submittedText = 'The player signed for two years.';
        $expectedHash = hash('sha256', mb_strtolower($submittedText));

        self::assertSame(
            'text://manual/' . substr($expectedHash, 0, 32),
            $this->callControllerMethod('buildManualTextInternalUrl', $submittedText, null, null)
        );
    }

    private function callControllerMethod(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = new ReflectionMethod(HomeController::class, $method);

        return $reflectionMethod->invoke(new HomeController(), ...$args);
    }
}
