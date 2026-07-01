<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClaimVerifiabilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClaimVerifiabilityServiceTest extends TestCase
{
    #[DataProvider('provideAssessmentCases')]
    public function testAssessReturnsCurrentVerifiabilityDecision(
        string $claim,
        array $expected
    ): void {
        $result = (new ClaimVerifiabilityService())->assess($claim);

        foreach ($expected as $key => $expectedValue) {
            if ($key === 'signals') {
                foreach ($expectedValue as $signalKey => $signalValue) {
                    self::assertSame($signalValue, $result['signals'][$signalKey] ?? null);
                }

                continue;
            }

            self::assertSame($expectedValue, $result[$key] ?? null);
        }
    }

    public static function provideAssessmentCases(): iterable
    {
        yield 'clear English factual claim is accepted' => [
            'The company launched a new AI tool today.',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'business',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => true,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => true,
                    'hasSoftAction' => false,
                    'hasCheckableDetail' => true,
                ],
            ],
        ];

        yield 'English opinion is rejected' => [
            'This minister is useless.',
            [
                'verifiable' => false,
                'reason' => 'The claim does not contain a concrete factual action or assertion.',
                'missingElements' => ['action'],
                'claimType' => 'politics',
                'subjectPresent' => true,
                'actionPresent' => false,
                'checkableDetailPresent' => false,
                'vaguenessLevel' => 'high',
                'signals' => [],
            ],
        ];

        yield 'vague English rumor is rejected' => [
            'Breaking rumor big surprise soon',
            [
                'verifiable' => false,
                'reason' => 'The claim does not contain an identifiable subject.',
                'missingElements' => ['subject'],
                'claimType' => 'general',
                'subjectPresent' => false,
                'actionPresent' => true,
                'checkableDetailPresent' => false,
                'vaguenessLevel' => 'high',
                'signals' => [],
            ],
        ];

        yield 'soft future English claim with concrete details is accepted' => [
            'The president will visit France on Monday.',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'politics',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => true,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => false,
                    'hasSoftAction' => true,
                    'hasCheckableDetail' => true,
                ],
            ],
        ];

        yield 'clear Arabic factual claim is accepted' => [
            'الوزارة أعلنت فتح التسجيل اليوم',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'politics',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => true,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => true,
                    'hasSoftAction' => false,
                    'hasCheckableDetail' => true,
                ],
            ],
        ];

        yield 'vague Arabic rumor is rejected' => [
            'عاجل مصادر خاصة مفاجأة قريبا',
            [
                'verifiable' => false,
                'reason' => 'The claim does not contain an identifiable subject.',
                'missingElements' => ['subject'],
                'claimType' => 'general',
                'subjectPresent' => false,
                'actionPresent' => true,
                'checkableDetailPresent' => false,
                'vaguenessLevel' => 'high',
                'signals' => [],
            ],
        ];

        yield 'clear French factual claim is accepted' => [
            'La societe a lance un nouvel outil aujourd hui.',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'general',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => true,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => true,
                    'hasSoftAction' => false,
                    'hasCheckableDetail' => true,
                ],
            ],
        ];

        yield 'soft future French claim with concrete details is accepted' => [
            'Le president devrait aller en France lundi.',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'politics',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => true,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => false,
                    'hasSoftAction' => true,
                    'hasCheckableDetail' => true,
                ],
            ],
        ];

        yield 'two-letter abbreviation with action but no detail is rejected' => [
            'UN approved',
            [
                'verifiable' => false,
                'reason' => 'The claim lacks enough checkable context to verify safely.',
                'missingElements' => ['object/event/date/source/context'],
                'claimType' => 'general',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => false,
                'vaguenessLevel' => 'high',
                'signals' => [],
            ],
        ];

        yield 'three-letter abbreviation with action is currently accepted without explicit detail' => [
            'CAF postponed',
            [
                'verifiable' => true,
                'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
                'missingElements' => [],
                'claimType' => 'general',
                'subjectPresent' => true,
                'actionPresent' => true,
                'checkableDetailPresent' => false,
                'vaguenessLevel' => 'low',
                'signals' => [
                    'hasStrongAction' => true,
                    'hasSoftAction' => false,
                    'hasCheckableDetail' => false,
                ],
            ],
        ];
    }
}
