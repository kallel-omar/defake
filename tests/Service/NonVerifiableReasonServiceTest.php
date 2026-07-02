<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NonVerifiableReasonService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonVerifiableReasonServiceTest extends TestCase
{
    #[DataProvider('provideReasonCases')]
    public function testClassifiesNonVerifiableReasons(string $text, string $expectedCode, string $expectedTitle): void
    {
        $result = (new NonVerifiableReasonService())->classify($text);

        self::assertSame($expectedCode, $result['code']);
        self::assertSame($expectedTitle, $result['title']);
        self::assertNotSame('', $result['summary']);
        self::assertNotSame('', $result['explanation']);
        self::assertNotSame('', $result['verificationReason']);
    }

    public static function provideReasonCases(): iterable
    {
        yield 'opinion only' => [
            'I feel like Tunisian football is becoming worse every year',
            NonVerifiableReasonService::OPINION_ONLY,
            'Opinion, not a factual claim',
        ];

        yield 'joke or sarcasm' => [
            'Tunisian football is so bad even Google refuses to search it',
            NonVerifiableReasonService::JOKE_OR_SARCASM,
            'Joke or sarcasm detected',
        ];

        yield 'advertisement' => [
            'Buy your official CSS jersey now with 20% discount',
            NonVerifiableReasonService::ADVERTISEMENT,
            'Advertisement detected',
        ];

        yield 'question only' => [
            'Did Moumen Rahmani sign with CSS?',
            NonVerifiableReasonService::QUESTION_ONLY,
            'Question detected',
        ];

        yield 'insult only' => [
            'الحكم فاسد ومكانه مزبلة التاريخ',
            NonVerifiableReasonService::INSULT_ONLY,
            'Insult or emotional accusation',
        ];

        yield 'vague rumor' => [
            'Breaking sources say a big surprise is coming soon',
            NonVerifiableReasonService::VAGUE_RUMOR,
            'Vague rumor detected',
        ];

        yield 'discussion or reminder' => [
            'Reminder: what do you think about the refereeing debate this week',
            NonVerifiableReasonService::DISCUSSION_OR_REMINDER,
            'Discussion or reminder detected',
        ];

        yield 'too many claims' => [
            'The coach resigned. The club signed a player. The match was postponed after the federation announced a new date.',
            NonVerifiableReasonService::TOO_MANY_CLAIMS,
            'Multiple claims detected',
        ];

        yield 'long Jamel Haimoudi post needs one standalone claim' => [
            'جمال الحيمودي قال إن لقطة النادي الإفريقي وسليمان فيها أكثر من زاوية، الحكم كان بعيد، تقنية الفار تأخرت، الجماهير غضبانة، والإعلام زاد ضغط كبير على الحكام. الكلام يخلط بين التسلل وضربة الجزاء والهدف الملغى وأداء الجامعة والتحكيم في تونس من غير ما يختار واقعة واحدة واضحة للتحقق.',
            NonVerifiableReasonService::NO_SINGLE_STANDALONE_CLAIM,
            'No single standalone claim detected',
        ];
    }

    public function testInsultLanguageAloneDoesNotClassifyTextWithFactualSportsActionAsInsultOnly(): void
    {
        $result = (new NonVerifiableReasonService())->classify(
            'الحكم فضيحة، الترجي فاز بالسوبر بعد ضربة جزاء غير صحيحة'
        );

        self::assertNotSame(NonVerifiableReasonService::INSULT_ONLY, $result['code']);
    }
}
