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
        $query = $postText;
        $data = $this->callSerper('news', $query);
        $items = $data['news'] ?? [];

        if (empty($items)) {
            $data = $this->callSerper('search', $query);
            $items = $data['organic'] ?? [];
        }

        if (empty($items)) {
            return [
                'text' => 'No internet evidence found.',
                'items' => [],
            ];
        }

        $results = [];
        $rankedItems = [];

        foreach (array_slice($items, 0, 10) as $item) {
            $relevanceScore = $this->evidenceRankingService->scoreEvidenceRelevance($item, $claim ?? $postText);

            if ($relevanceScore < 3) {
                continue;
            }

            $link = $item['link'] ?? '';

            if ($link === '') {
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

        if (empty($rankedItems)) {
            return [
                'text' => 'No relevant internet evidence found. Search results existed, but they did not match the key claim context.',
                'items' => [],
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
                    'num' => 5,
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
