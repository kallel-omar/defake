<?php

namespace App\Service;

use App\Exception\AnalysisConfigurationException;
use App\Exception\AnalysisPermanentException;
use App\Exception\AnalysisTransientException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class InternetEvidenceSearchService
{
    public function __construct(
        private readonly string $serperApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly SourceConfidenceService $sourceConfidenceService,
        private readonly EvidenceRankingService $evidenceRankingService,
    ) {
    }

   public function search(string $postText, ?string $claim = null): array
{
    $query = trim((string) $claim);

if ($query === '') {
    $query = trim($postText);
}

    $debug = [
        'query' => $query,
        'claim' => $claim,
        'endpoint' => 'news',
        'rawItemsCount' => 0,
        'rankingRejected' => [],
        'rankingAccepted' => [],
    ];

    $data = $this->callSerper('news', $query);
    $items = $data['news'] ?? [];
    $debug['rawItemsCount'] = count($items);

    if (empty($items)) {
        $debug['endpoint'] = 'search';

        $data = $this->callSerper('search', $query);
        $items = $data['organic'] ?? [];
        $debug['rawItemsCount'] = count($items);
    }

    if (empty($items)) {
        return [
            'text' => 'No internet evidence found.',
            'items' => [],
            'debug' => $debug,
        ];
    }

    $results = [];
    $rankedItems = [];

    foreach (array_slice($items, 0, 10) as $item) {
        $title = $item['title'] ?? 'No title';
        $snippet = $item['snippet'] ?? '';
        $link = $item['link'] ?? '';

        $relevanceScore = $this->evidenceRankingService->scoreEvidenceRelevance(
            $item,
            $claim ?? $postText
        );

        if ($relevanceScore < 3) {
            $debug['rankingRejected'][] = [
                'reason' => 'relevance_score_below_threshold',
                'title' => $title,
                'link' => $link,
                'relevanceScore' => $relevanceScore,
            ];

            continue;
        }

        if ($link === '') {
            $debug['rankingRejected'][] = [
                'reason' => 'missing_link',
                'title' => $title,
                'link' => $link,
                'relevanceScore' => $relevanceScore,
            ];

            continue;
        }

        $confidence = $this->sourceConfidenceService->score($link);

        $sourceScore = (int) ($confidence['score'] ?? 0);

$rankedItems[] = [
    'item' => $item,
    'relevanceScore' => $relevanceScore,
    'sourceScore' => $sourceScore,
    'sourceLabel' => $confidence['label'] ?? 'Unknown',

    // Relevance remains important, but strong sources should be able
    // to outrank weak websites when both match the same claim.
    'rankingScore' => ($relevanceScore * 4) + $sourceScore,
];
    }
usort($rankedItems, static function (array $a, array $b): int {
    return [
        $b['rankingScore'],
        $b['relevanceScore'],
        $b['sourceScore'],
    ] <=> [
        $a['rankingScore'],
        $a['relevanceScore'],
        $a['sourceScore'],
    ];
});

    foreach ($rankedItems as $rankedItem) {
        $item = $rankedItem['item'];

        $debug['rankingAccepted'][] = [
            'title' => $item['title'] ?? 'No title',
            'link' => $item['link'] ?? '',
            'source' => $item['source'] ?? '',
            'relevanceScore' => $rankedItem['relevanceScore'],
            'sourceScore' => $rankedItem['sourceScore'],
            'sourceLabel' => $rankedItem['sourceLabel'],
        ];
    }
$strongestCandidateSourceScore = 0;

foreach ($rankedItems as $rankedItem) {
    $strongestCandidateSourceScore = max(
        $strongestCandidateSourceScore,
        (int) ($rankedItem['sourceScore'] ?? 0)
    );
}

$needsWebSearchFallback =
    $debug['endpoint'] === 'news'
    && (
        empty($rankedItems)
        || $strongestCandidateSourceScore < 60
    );

if ($needsWebSearchFallback) {
    $debug['endpoint'] = 'search_fallback_after_weak_or_irrelevant_news';

    $searchData = $this->callSerper('search', $query);
    $searchItems = $searchData['organic'] ?? [];

    $debug['searchFallbackRawItemsCount'] = count($searchItems);

    foreach (array_slice($searchItems, 0, 10) as $item) {
        $title = $item['title'] ?? 'No title';
        $snippet = $item['snippet'] ?? '';
        $link = $item['link'] ?? '';

        $relevanceScore = $this->evidenceRankingService->scoreEvidenceRelevance(
            $item,
            $claim ?? $postText
        );

        if ($relevanceScore < 3) {
            $debug['rankingRejected'][] = [
                'reason' => 'relevance_score_below_threshold',
                'title' => $title,
                'link' => $link,
                'relevanceScore' => $relevanceScore,
                'endpoint' => 'search_fallback',
            ];

            continue;
        }

        if ($link === '') {
            $debug['rankingRejected'][] = [
                'reason' => 'missing_link',
                'title' => $title,
                'link' => $link,
                'relevanceScore' => $relevanceScore,
                'endpoint' => 'search_fallback',
            ];

            continue;
        }

        $confidence = $this->sourceConfidenceService->score($link);

        $rankedItems[] = [
            'item' => $item,
            'relevanceScore' => $relevanceScore,
            'sourceScore' => $confidence['score'] ?? 0,
            'sourceLabel' => $confidence['label'] ?? 'Unknown',
        ];
    }

    usort($rankedItems, static function (array $a, array $b): int {
        return [$b['relevanceScore'], $b['sourceScore']]
            <=> [$a['relevanceScore'], $a['sourceScore']];
    });
}

if (empty($rankedItems)) {
    return [
        'text' => 'No relevant internet evidence found. News and web search results did not sufficiently match the key claim context.',
        'items' => [],
        'debug' => $debug,
    ];
}

    $rankedEvidenceItems = [];

    foreach (array_slice($rankedItems, 0, 5) as $rankedItem) {
        $item = $rankedItem['item'];
        $rankedEvidenceItems[] = $item;

        $title = $item['title'] ?? 'No title';
        $snippet = $item['snippet'] ?? 'No snippet';
        $link = $item['link'] ?? 'No link';

        $results[] = "- Title: {$title}
  Snippet: {$snippet}
  Link: {$link}
  Relevance Score: {$rankedItem['relevanceScore']}
  Source Confidence: {$rankedItem['sourceScore']}/100
  Source Type: {$rankedItem['sourceLabel']}";
    }

    return [
        'text' => implode("\n\n", $results),
        'items' => $rankedEvidenceItems,
        'debug' => $debug,
    ];
}

    private function callSerper(string $type, string $query): array
    {
        if (trim($this->serperApiKey) === '') {
            throw new AnalysisConfigurationException(
                'Serper API key is missing.',
                'Evidence search is not configured correctly. Please try again later.'
            );
        }

        $endpoint = $type === 'news'
            ? 'https://google.serper.dev/news'
            : 'https://google.serper.dev/search';

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'X-API-KEY' => $this->serperApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'q' => $query,
                    'num' => 10,
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if (in_array($statusCode, [408, 409, 425, 429, 500, 502, 503, 504], true)) {
                throw new AnalysisTransientException(
                    'Serper temporary API error: HTTP ' . $statusCode,
                    'Evidence search is temporarily unavailable. DeFake will retry shortly.'
                );
            }

            if (in_array($statusCode, [401, 403], true)) {
                throw new AnalysisConfigurationException(
                    'Serper authentication failed: HTTP ' . $statusCode,
                    'Evidence search is not configured correctly. Please try again later.'
                );
            }

            if ($statusCode >= 400) {
                throw new AnalysisPermanentException(
                    'Serper rejected the request: HTTP ' . $statusCode,
                    'Evidence search rejected this request. Please try again with different content.'
                );
            }

            return $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new AnalysisTransientException(
                'Serper connection failed: ' . $e->getMessage(),
                'Evidence search could not be reached. DeFake will retry shortly.',
                previous: $e
            );
        } catch (DecodingExceptionInterface $e) {
            throw new AnalysisTransientException(
                'Serper returned invalid JSON: ' . $e->getMessage(),
                'Evidence search returned an invalid response. DeFake will retry shortly.',
                previous: $e
            );
        }
    }
}
