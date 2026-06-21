<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OfficialSourceDetectorService
{
    public function __construct(
        private readonly GroqAiService $groqAiService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $serperApiKey,
    ) {
    }

    public function detect(array $sourceContext, string $postText = ''): array
    {
        $pageName = trim((string) ($sourceContext['pageName'] ?? $sourceContext['userName'] ?? ''));

        if ($pageName === '') {
            return $this->result(false, 'unknown', 0, 'No Facebook page name was available.');
        }

        $googleEvidence = $this->searchOfficialEvidence($pageName);

        $prompt = <<<PROMPT
You are verifying whether a Facebook page is official.

Facebook page name:
{$pageName}

Facebook source context:
{$this->safeJson($sourceContext)}

Post text:
{$postText}

Google evidence:
{$googleEvidence}

Decide if this Facebook page is the official page of the organization itself.

Official means:
- official club page
- official company page
- official federation page
- official ministry page
- official government/public authority page
- official organization page

Return true only if Google evidence or page context strongly indicates the page belongs to the organization itself.

Return false if it is:
- fan page
- media page
- news page
- supporter page
- journalist page
- commentary page
- unofficial page

Return ONLY valid JSON:

{
  "official": false,
  "category": "unknown",
  "confidence": 0,
  "reason": ""
}
PROMPT;

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            return $this->fallbackDetection($pageName, $googleEvidence);
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim();

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return $this->fallbackDetection($pageName, $googleEvidence);
        }

        $official = (bool) ($data['official'] ?? false);
        $category = (string) ($data['category'] ?? 'unknown');
        $confidence = max(0, min(100, (int) ($data['confidence'] ?? 0)));
        $reason = (string) ($data['reason'] ?? 'No reason provided.');

        $allowed = [
            'government',
            'ministry',
            'public_authority',
            'company',
            'club',
            'federation',
            'organization',
            'media',
            'fan_page',
            'journalist',
            'commentary',
            'unknown',
        ];

        if (!in_array($category, $allowed, true)) {
            $category = 'unknown';
        }

        return $this->result($official, $category, $confidence, $reason);
    }

    private function searchOfficialEvidence(string $pageName): string
    {
        $query = $pageName . ' official Facebook page site:facebook.com OR official website';

        $response = $this->httpClient->request('POST', 'https://google.serper.dev/search', [
            'headers' => [
                'X-API-KEY' => $this->serperApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'q' => $query,
                'num' => 5,
            ],
            'timeout' => 20,
        ]);

        $data = $response->toArray(false);
        $items = $data['organic'] ?? [];

        if (empty($items)) {
            return 'No Google evidence found.';
        }

        $results = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $title = $item['title'] ?? '';
            $snippet = $item['snippet'] ?? '';
            $link = $item['link'] ?? '';

            $results[] = "Title: {$title}\nSnippet: {$snippet}\nLink: {$link}";
        }

        return implode("\n\n", $results);
    }

    private function fallbackDetection(string $pageName, string $googleEvidence): array
    {
        $text = strtolower($pageName . ' ' . $googleEvidence);

        $officialWords = [
            'official',
            'page officielle',
            'verified',
            'club',
            'federation',
            'fédération',
            'ministry',
            'ministère',
            'government',
            'gouvernement',
            'company',
            'organization',
        ];

        foreach ($officialWords as $word) {
            if (str_contains($text, $word)) {
                return $this->result(
                    true,
                    'organization',
                    75,
                    'Google evidence suggests this page may be an official organization page.'
                );
            }
        }

        return $this->result(
            false,
            'unknown',
            40,
            'The system could not confirm from Google evidence that this is an official page.'
        );
    }

    private function safeJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function result(bool $official, string $category, int $confidence, string $reason): array
    {
        return [
            'official' => $official,
            'category' => $category,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}