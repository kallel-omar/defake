<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PostCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostCheckTest extends TestCase
{
    #[DataProvider('provideCountryCodes')]
    public function testContextCountryPersistsForManualTextCheck(string $countryCode): void
    {
        $postCheck = new PostCheck();

        $postCheck
            ->setContentType('manual_text')
            ->setContextCountry($countryCode)
            ->setContextTopic('sports');

        self::assertSame('manual_text', $postCheck->getContentType());
        self::assertSame($countryCode, $postCheck->getContextCountry());
        self::assertSame('sports', $postCheck->getContextTopic());
    }

    public static function provideCountryCodes(): iterable
    {
        yield 'global' => ['GLOBAL'];
        yield 'tunisia' => ['TN'];
        yield 'united states' => ['US'];
        yield 'qatar' => ['QA'];
    }
}
