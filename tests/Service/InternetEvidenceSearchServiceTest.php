<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EvidenceRankingService;
use App\Service\InternetEvidenceSearchService;
use App\Service\SourceConfidenceService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InternetEvidenceSearchServiceTest extends TestCase
{
    public function testSearchReturnsRankedFilteredItemsInsteadOfRawSerperOrder(): void
    {
        $lowRelevance = self::item('Low relevance result', 'https://low.example/news');
        $good = self::item('Good result', 'https://good.example/news');
        $best = self::item('Best result', 'https://best.example/news');

        $service = $this->createService(
            [$lowRelevance, $good, $best],
            [
                'Low relevance result' => 1,
                'Good result' => 4,
                'Best result' => 6,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([$best, $good], $result['items']);
    }

    public function testSearchExcludesResultsWithRelevanceScoreBelowThree(): void
    {
        $relevant = self::item('Relevant result', 'https://relevant.example/news');
        $weak = self::item('Weak result', 'https://weak.example/news');
        $irrelevant = self::item('Irrelevant result', 'https://irrelevant.example/news');

        $service = $this->createService(
            [$relevant, $weak, $irrelevant],
            [
                'Relevant result' => 3,
                'Weak result' => 2,
                'Irrelevant result' => 0,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([$relevant], $result['items']);
    }

    public function testSearchExcludesRelevantResultsWithoutLink(): void
    {
        $withoutLink = self::item('Relevant result without link', null);
        $withLink = self::item('Relevant linked result', 'https://linked.example/news');

        $service = $this->createService(
            [$withoutLink, $withLink],
            [
                'Relevant result without link' => 6,
                'Relevant linked result' => 4,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([$withLink], $result['items']);
    }

    public function testSearchSortsItemsByRelevanceScoreDescending(): void
    {
        $leastRelevant = self::item('Least relevant passing result', 'https://least.example/news');
        $mostRelevant = self::item('Most relevant result', 'https://most.example/news');
        $middleRelevant = self::item('Middle relevant result', 'https://middle.example/news');

        $service = $this->createService(
            [$leastRelevant, $mostRelevant, $middleRelevant],
            [
                'Least relevant passing result' => 3,
                'Most relevant result' => 8,
                'Middle relevant result' => 5,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([$mostRelevant, $middleRelevant, $leastRelevant], $result['items']);
    }

    public function testSearchUsesSourceConfidenceAsTieBreakerWhenRelevanceTies(): void
    {
        $lowerConfidence = self::item('Lower confidence result', 'https://lower.example/news');
        $higherConfidence = self::item('Higher confidence result', 'https://higher.example/news');

        $service = $this->createService(
            [$lowerConfidence, $higherConfidence],
            [
                'Lower confidence result' => 5,
                'Higher confidence result' => 5,
            ],
            [
                'https://lower.example/news' => 60,
                'https://higher.example/news' => 90,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([$higherConfidence, $lowerConfidence], $result['items']);
    }

    public function testSearchReturnsEmptyItemsWhenSerperResultsDoNotPassRelevanceFiltering(): void
    {
        $weak = self::item('Weak result', 'https://weak.example/news');
        $irrelevant = self::item('Irrelevant result', 'https://irrelevant.example/news');

        $service = $this->createService(
            [$weak, $irrelevant],
            [
                'Weak result' => 2,
                'Irrelevant result' => 0,
            ]
        );

        $result = $service->search('original post text', 'claim to verify');

        self::assertSame([], $result['items']);
    }

    /**
     * @param list<array<string, mixed>> $newsItems
     * @param array<string, int> $relevanceByTitle
     * @param array<string, int> $sourceScoreByLink
     */
    private function createService(
        array $newsItems,
        array $relevanceByTitle,
        array $sourceScoreByLink = []
    ): InternetEvidenceSearchService {
        $httpClient = new MockHttpClient(new MockResponse(
            json_encode(['news' => $newsItems]) ?: '{}',
            [
                'http_code' => 200,
                'response_headers' => [
                    'content-type' => 'application/json',
                ],
            ]
        ));

        $sourceConfidenceService = $this->createMock(SourceConfidenceService::class);
        $sourceConfidenceService
            ->method('score')
            ->willReturnCallback(static function (string $link) use ($sourceScoreByLink): array {
                $score = $sourceScoreByLink[$link] ?? 70;

                return [
                    'score' => $score,
                    'label' => 'Test source',
                    'type' => 'media',
                ];
            });

        $evidenceRankingService = $this->createMock(EvidenceRankingService::class);
        $evidenceRankingService
            ->method('scoreEvidenceRelevance')
            ->willReturnCallback(static function (array $item) use ($relevanceByTitle): int {
                return $relevanceByTitle[(string) ($item['title'] ?? '')] ?? 0;
            });

        return new InternetEvidenceSearchService(
            'test-serper-key',
            $httpClient,
            $sourceConfidenceService,
            $evidenceRankingService
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $title, ?string $link): array
    {
        $item = [
            'title' => $title,
            'snippet' => 'Snippet with claim context.',
            'source' => 'Test Source',
        ];

        if ($link !== null) {
            $item['link'] = $link;
        }

        return $item;
    }
}
