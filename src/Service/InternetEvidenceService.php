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
        $data = $this->callSerper('news', $claim);
        $items = $data['news'] ?? [];

        if (empty($items)) {
            $data = $this->callSerper('search', $claim);
            $items = $data['organic'] ?? [];
        }

        if (empty($items)) {
            return 'No internet evidence found.';
        }

        $results = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $title = $item['title'] ?? 'No title';
            $snippet = $item['snippet'] ?? 'No snippet';
            $link = $item['link'] ?? 'No link';

            $confidence = $this->sourceConfidenceService->score($link);

            $results[] = "- Title: {$title}
  Snippet: {$snippet}
  Link: {$link}
  Source Confidence: {$confidence['score']}/100
  Source Type: {$confidence['label']}";
        }

        return implode("\n\n", $results);
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
                'num' => 5,
            ],
            'timeout' => 30,
        ]);

        return $response->toArray(false);
    }
}