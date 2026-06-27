<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class InternetEvidenceService
{
    public function __construct(
        private readonly string $serperApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly SourceConfidenceService $sourceConfidenceService
    ) {
    }

    public function search(string $claim): string
    {
        $query = $this->buildSearchQuery($claim);

        $data = $this->callSerper('news', $query);
        $items = $data['news'] ?? [];

        if (empty($items)) {
            $data = $this->callSerper('search', $query);
            $items = $data['organic'] ?? [];
        }

        if (empty($items)) {
            return 'No internet evidence found.';
        }

        $rankedItems = [];

        foreach (array_slice($items, 0, 10) as $item) {
            $relevanceScore = $this->scoreRelevance($item, $claim);

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
            return 'No relevant internet evidence found. Search results existed, but they did not match the key claim context.';
        }

        $results = [];

        foreach (array_slice($rankedItems, 0, 5) as $rankedItem) {
            $item = $rankedItem['item'];

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

        return implode("\n\n", $results);
    }

    private function buildSearchQuery(string $claim): string
    {
        $claim = trim(preg_replace('/\s+/', ' ', $claim));

        $terms = $this->extractSearchTerms($claim);

        if (count($terms) < 3) {
            return $claim;
        }

        return implode(' ', array_slice($terms, 0, 10));
    }

    private function extractSearchTerms(string $text): array
    {
        $text = trim($text);

        $words = preg_split('/[^\p{L}\p{N}.%]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!$words) {
            return [];
        }

        $stopWords = [
            'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with',
            'from', 'that', 'this', 'these', 'those', 'while', 'and', 'or', 'but',
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
            'new', 'said', 'says', 'reported', 'claimed', 'according', 'confirm',
            'confirms', 'confirmed', 'announced',
        ];

        $terms = [];

        foreach ($words as $word) {
            $cleanWord = trim($word);
            $lowerWord = mb_strtolower($cleanWord);

            if (mb_strlen($cleanWord) < 3) {
                continue;
            }

            if (in_array($lowerWord, $stopWords, true)) {
                continue;
            }

            $terms[] = $cleanWord;
        }

        return array_values(array_unique($terms));
    }

    private function scoreRelevance(array $item, string $claim): int
    {
        $title = (string) ($item['title'] ?? '');
        $snippet = (string) ($item['snippet'] ?? '');

        $haystack = mb_strtolower($title . ' ' . $snippet);
        $terms = $this->extractSearchTerms($claim);

        $score = 0;

        foreach ($terms as $term) {
            $term = mb_strtolower($term);

            if (str_contains($haystack, $term)) {
                $score++;
            }
        }

        return $score;
    }

    private function callSerper(string $type, string $query): array
    {
        $endpoint = $type === 'news'
            ? 'https://google.serper.dev/news'
            : 'https://google.serper.dev/search';

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

        return $response->toArray(false);
    }
}