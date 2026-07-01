<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClaimExtractionService;
use App\Service\GroqAiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClaimExtractionServiceTest extends TestCase
{
    private const NO_VERIFIABLE_CLAIM = 'NO_VERIFIABLE_CLAIM';

    public function testFactCheckableTrueReturnsMainClaim(): void
    {
        $postText = 'The company launched a new AI tool today.';
        $mainClaim = 'The company launched a new AI tool today.';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says a company launched an AI tool.',
            'reason' => 'Contains a public factual claim.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([$mainClaim], $result);
    }

    public function testFactCheckableFalseReturnsNoVerifiableClaim(): void
    {
        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'opinion',
            'fact_checkable' => false,
            'summary' => 'The post is an opinion.',
            'reason' => 'No concrete factual claim.',
            'main_claim' => null,
            'secondary_claims' => [],
        ]))->extract('This minister is useless.');

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    public function testStringFalseFactCheckableReturnsNoVerifiableClaim(): void
    {
        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'opinion',
            'fact_checkable' => 'false',
            'summary' => 'The post is not fact checkable.',
            'reason' => 'No concrete factual claim.',
            'main_claim' => 'The company launched a new AI tool today.',
            'secondary_claims' => [],
        ]))->extract('The company launched a new AI tool today.');

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    #[DataProvider('provideInvalidAiResponses')]
    public function testInvalidOrEmptyAiResponseReturnsNoVerifiableClaim(?string $aiResponse): void
    {
        $result = $this->serviceWithAiResponse($aiResponse)->extract('The company launched a new AI tool today.');

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    public static function provideInvalidAiResponses(): iterable
    {
        yield 'empty response' => [''];
        yield 'null response' => [null];
        yield 'invalid JSON' => ['not valid json'];
    }

    public function testValidMainClaimIsPreservedExactly(): void
    {
        $postText = 'The court sentenced the former official to 3 years in prison.';
        $mainClaim = 'The court sentenced the former official to 3 years in prison.';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says a former official received a prison sentence.',
            'reason' => 'Contains a clear legal claim.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([$mainClaim], $result);
    }

    public function testLegacyClaimsArrayBehaviorIsCovered(): void
    {
        $postText = 'The ministry announced that registration will open on 1 July.';
        $legacyClaim = 'The ministry announced that registration will open on 1 July.';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says registration will open on 1 July.',
            'reason' => 'Contains a clear official announcement.',
            'claims' => [$legacyClaim],
        ]))->extract($postText);

        self::assertSame([$legacyClaim], $result);
    }

    public function testShortUppercaseAcronymAnchorMatchesCaseInsensitively(): void
    {
        $postText = 'Mo2men Rahmani in css for 2 years';
        $mainClaim = 'Mo2men Rahmani in CSS for 2 years';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says Mo2men Rahmani is in css for 2 years.',
            'reason' => 'Contains a clear factual claim.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([$mainClaim], $result);
    }

    public function testSportsContextAllowsInferredSigningForImplicitRelationshipDetailClaim(): void
    {
        $postText = 'Mo2men Rahmani in css for 2 years';
        $mainClaim = 'Mo2men Rahmani signed with CSS for 2 years';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says Mo2men Rahmani is linked to css for two years.',
            'reason' => 'Sports context makes the implicit relationship detail checkable.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText, [
            'country' => 'TN',
            'topic' => 'sports',
        ]);

        self::assertSame([$mainClaim], $result);
    }

    public function testInferredSigningWithoutSportsContextIsRejectedAsAddedActionDrift(): void
    {
        $postText = 'Mo2men Rahmani in css for 2 years';
        $mainClaim = 'Mo2men Rahmani signed with CSS for 2 years';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says Mo2men Rahmani is linked to css for two years.',
            'reason' => 'The AI inferred a signing action.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    public function testSportsContextDoesNotAllowInferredSigningWithoutConcreteRelationshipDetail(): void
    {
        $postText = 'Mo2men Rahmani in css';
        $mainClaim = 'Mo2men Rahmani signed with CSS';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post mentions Mo2men Rahmani and css.',
            'reason' => 'The AI inferred a signing action.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText, [
            'country' => 'TN',
            'topic' => 'sports',
        ]);

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    #[DataProvider('provideClaimDriftCases')]
    public function testRejectsClaimDrift(string $postText, string $driftedClaim): void
    {
        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post contains a factual claim.',
            'reason' => 'Contains a factual claim.',
            'main_claim' => $driftedClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    public static function provideClaimDriftCases(): iterable
    {
        yield 'changed percentage' => [
            'Fuel prices increased by 5%.',
            'Fuel prices increased by 15%.',
        ];

        yield 'changed denial into announcement' => [
            'The ministry denied that registration opened today.',
            'The ministry announced that registration opened today.',
        ];

        yield 'changed close to signing into signed' => [
            'The player is close to signing with Club A for two seasons.',
            'The player signed with Club A for two seasons.',
        ];

        yield 'changed date word' => [
            'The president will visit France on Monday.',
            'The president will visit France on Tuesday.',
        ];

        yield 'changed short country entity' => [
            'US approved new sanctions today.',
            'UK approved new sanctions today.',
        ];
    }

    #[DataProvider('provideClaimDriftCasesThatContextMustNotAllow')]
    public function testSportsContextDoesNotAllowCriticalClaimDrift(string $postText, string $driftedClaim): void
    {
        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post contains a factual claim.',
            'reason' => 'Contains a factual claim.',
            'main_claim' => $driftedClaim,
            'secondary_claims' => [],
        ]))->extract($postText, [
            'country' => 'TN',
            'topic' => 'sports',
        ]);

        self::assertSame([self::NO_VERIFIABLE_CLAIM], $result);
    }

    public static function provideClaimDriftCasesThatContextMustNotAllow(): iterable
    {
        yield 'changed percentage is still rejected' => [
            'Fuel prices increased by 5%.',
            'Fuel prices increased by 15%.',
        ];

        yield 'changed denial into announcement is still rejected' => [
            'The ministry denied that registration opened today.',
            'The ministry announced that registration opened today.',
        ];

        yield 'changed date word is still rejected' => [
            'The president will visit France on Monday.',
            'The president will visit France on Tuesday.',
        ];

        yield 'changed short country entity is still rejected' => [
            'US approved new sanctions today.',
            'UK approved new sanctions today.',
        ];
    }

    public function testValidArabicClaimIsPreserved(): void
    {
        $postText = 'الوزارة أعلنت فتح التسجيل اليوم';
        $mainClaim = 'الوزارة أعلنت فتح التسجيل اليوم';

        $result = $this->serviceWithAiResponse($this->aiResponse([
            'content_type' => 'claim',
            'fact_checkable' => true,
            'summary' => 'The post says the ministry announced opening registration today.',
            'reason' => 'Contains a clear factual claim.',
            'main_claim' => $mainClaim,
            'secondary_claims' => [],
        ]))->extract($postText);

        self::assertSame([$mainClaim], $result);
    }

    private function serviceWithAiResponse(?string $aiResponse): ClaimExtractionService
    {
        $groqAiService = $this->createMock(GroqAiService::class);
        $groqAiService
            ->expects(self::once())
            ->method('ask')
            ->willReturn($aiResponse);

        return new ClaimExtractionService($groqAiService);
    }

    private function aiResponse(array $response): string
    {
        return (string) json_encode($response, JSON_UNESCAPED_UNICODE);
    }
}
