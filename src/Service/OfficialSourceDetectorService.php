<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class OfficialSourceDetectorService
{
    private const ALLOWED_CATEGORIES = [
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

    public function __construct(
        private readonly GroqAiService $groqAiService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $serperApiKey,
    ) {
    }

    public function detect(array $sourceContext, string $postText = ''): array
    {
        $pageName = $this->extractPageName($sourceContext);

        if ($pageName === '') {
            return $this->result(
                false,
                'unknown',
                0,
                'No Facebook page name was available.'
            );
        }

        $googleEvidence = $this->searchOfficialEvidence($pageName);

        $prompt = $this->buildPrompt(
            $pageName,
            $sourceContext,
            $postText,
            $googleEvidence
        );

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'You verify whether a Facebook page is official. Return only valid JSON. No markdown.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            return $this->fallbackDetection($pageName, $googleEvidence);
        }

        $data = $this->decodeJson($content);

        if (!is_array($data)) {
            return $this->fallbackDetection($pageName, $googleEvidence);
        }

        $official = (bool) ($data['official'] ?? false);
        $category = $this->normalizeCategory((string) ($data['category'] ?? 'unknown'));
        $confidence = max(0, min(100, (int) ($data['confidence'] ?? 0)));
        $reason = trim((string) ($data['reason'] ?? 'No reason provided.'));

        if ($reason === '') {
            $reason = 'No reason provided.';
        }

        return $this->result(
            $official,
            $category,
            $confidence,
            $reason
        );
    }

    private function extractPageName(array $sourceContext): string
    {
        $pageName = trim((string) ($sourceContext['pageName'] ?? ''));

        if ($pageName !== '') {
            return $pageName;
        }

        $userName = trim((string) ($sourceContext['userName'] ?? ''));

        if ($userName !== '') {
            return $userName;
        }

        $name = trim((string) ($sourceContext['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return '';
    }

    private function buildPrompt(
        string $pageName,
        array $sourceContext,
        string $postText,
        string $googleEvidence
    ): string {
        $sourceJson = $this->safeJson($sourceContext);

        return <<<PROMPT
Decide if this Facebook page is the OFFICIAL page of the organization itself.

Facebook page name:
{$pageName}

Facebook source context:
{$sourceJson}

Post text:
{$postText}

Google evidence:
{$googleEvidence}

Official means the page belongs directly to:
- a sports club
- a company
- a ministry
- a government or public authority
- a federation
- an official organization

Return true only if the page itself appears to belong to the organization, and the post concerns that same organization, its own activity, announcement, members, services, or decisions.

Return false if the page is:
- fan page
- supporter page
- media page
- news page
- journalist page
- commentary page
- rumor page
- unofficial page

Important rules:
- A verified badge alone is not enough.
- A media/news page reporting about a club is not official.
- A fan/supporter page is not official.
- If Google evidence clearly says official page, page officielle, official website, or site officiel, this supports official=true.
- If uncertain, return false.

Category must be exactly one of:
government, ministry, public_authority, company, club, federation, organization, media, fan_page, journalist, commentary, unknown

Return only valid JSON with this structure:

{
  "official": false,
  "category": "unknown",
  "confidence": 0,
  "reason": "short reason"
}
PROMPT;
    }

    private function searchOfficialEvidence(string $pageName): string
    {
        if ($this->serperApiKey === '') {
            return 'No Google evidence found because SERPER_API_KEY is missing.';
        }

        $query = sprintf(
            '"%s" "official Facebook page" OR "%s" "page officielle" OR "%s" "official website" OR "%s" "site officiel"',
            $pageName,
            $pageName,
            $pageName,
            $pageName
        );

        try {
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
        } catch (Throwable $e) {
            return 'Google evidence search failed: ' . $e->getMessage();
        }

        $items = $data['organic'] ?? [];

        if (empty($items)) {
            return 'No Google evidence found.';
        }

        $results = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $snippet = trim((string) ($item['snippet'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));

            $results[] = "Title: {$title}\nSnippet: {$snippet}\nLink: {$link}";
        }

        return implode("\n\n", $results);
    }

    private function fallbackDetection(string $pageName, string $googleEvidence): array
    {
        $text = mb_strtolower($pageName . ' ' . $googleEvidence);

        $unofficialSignals = [
            'fan page',
            'supporter',
            'supporters',
            'unofficial',
            'non officiel',
            'غير رسمية',
            'news',
            'media',
            'journalist',
            'rumor',
            'rumour',
            'commentary',
        ];

        foreach ($unofficialSignals as $signal) {
            if (str_contains($text, $signal)) {
                return $this->result(
                    false,
                    'unknown',
                    70,
                    'Google evidence suggests this may be a fan, media, supporter, or unofficial page.'
                );
            }
        }

        $strongOfficialSignals = [
            'official facebook page',
            'official page',
            'page officielle',
            'site officiel',
            'official website',
            'verified official',
            'facebook officiel',
        ];

        foreach ($strongOfficialSignals as $signal) {
            if (str_contains($text, $signal)) {
                return $this->result(
                    true,
                    $this->guessCategory($pageName),
                    75,
                    'Google evidence contains strong official-source wording for this Facebook page or organization.'
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

    private function guessCategory(string $pageName): string
    {
        $name = mb_strtolower($pageName);

        if (
            str_contains($name, 'ministère') ||
            str_contains($name, 'ministere') ||
            str_contains($name, 'ministry') ||
            str_contains($name, 'وزارة')
        ) {
            return 'ministry';
        }

        if (
            str_contains($name, 'gouvernement') ||
            str_contains($name, 'government') ||
            str_contains($name, 'public authority') ||
            str_contains($name, 'الهيئة') ||
            str_contains($name, 'الحكومة')
        ) {
            return 'government';
        }

        if (
            str_contains($name, 'fédération') ||
            str_contains($name, 'federation') ||
            str_contains($name, 'fifa') ||
            str_contains($name, 'caf') ||
            str_contains($name, 'جامعة')
        ) {
            return 'federation';
        }

        if (
            str_contains($name, 'club') ||
            str_contains($name, 'sportive') ||
            str_contains($name, 'sportif') ||
            str_contains($name, 'fc') ||
            str_contains($name, 'sc') ||
            str_contains($name, 'espérance') ||
            str_contains($name, 'esperance') ||
            str_contains($name, 'taraji') ||
            str_contains($name, 'الترجي') ||
            str_contains($name, 'النادي')
        ) {
            return 'club';
        }

        if (
            str_contains($name, 'company') ||
            str_contains($name, 'group') ||
            str_contains($name, 'bank') ||
            str_contains($name, 'airways') ||
            str_contains($name, 'airlines') ||
            str_contains($name, 'شركة') ||
            str_contains($name, 'بنك')
        ) {
            return 'company';
        }

        return 'organization';
    }

    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $content = trim($content);

        $data = json_decode($content, true);

        if (is_array($data)) {
            return $data;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function normalizeCategory(string $category): string
    {
        $category = trim($category);

        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return 'unknown';
        }

        return $category;
    }

    private function safeJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }

    private function result(
        bool $official,
        string $category,
        int $confidence,
        string $reason
    ): array {
        return [
            'official' => $official,
            'category' => $this->normalizeCategory($category),
            'confidence' => max(0, min(100, $confidence)),
            'reason' => $reason,
        ];
    }
}