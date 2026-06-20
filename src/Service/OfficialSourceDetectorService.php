<?php

namespace App\Service;

class OfficialSourceDetectorService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function detect(array $sourceContext, string $postText = ''): array
    {
        if (empty($sourceContext)) {
            return [
                'official' => false,
                'category' => 'unknown',
                'confidence' => 0,
                'reason' => 'No source context was available.',
            ];
        }

        $sourceText = json_encode($sourceContext, JSON_UNESCAPED_UNICODE);

        $pageName = strtolower((string) ($sourceContext['pageName'] ?? ''));
        $userName = strtolower((string) ($sourceContext['userName'] ?? ''));
        $combinedName = $pageName . ' ' . $userName;

        $prompt = <<<PROMPT
You are helping a fact-checking system evaluate a Facebook source.

Task:
Decide if this Facebook source is the OFFICIAL page of the organization itself.

Definition of official source:
Official means the Facebook page belongs to one of these:
- government institution
- ministry
- public authority
- company
- sports club
- federation
- official organization

Return true ONLY when:
- the page name clearly represents the organization itself
- and the post is about that same organization, its own activity, its own announcement, or its own members

Return false when the page is:
- news page
- sports media page
- fan page
- supporter page
- journalist page
- commentary page
- rumor page
- page only reporting about another organization

Important:
- A verified badge alone does not mean official.
- A page can be trusted but still not official.
- A professional-looking name does not mean official.
- If the page name is exactly or very closely the name of a club, company, ministry, federation, or organization, and the post concerns that same entity, it can be official.
- If uncertain, return false.

The category field must contain exactly ONE value from this list:
government, ministry, public_authority, company, club, federation, organization, media, fan_page, journalist, commentary, unknown.

Do not return the full list as the category.

Facebook source context:
$sourceText

Facebook post text:
$postText

Return ONLY valid JSON with this exact structure:

{
  "official": false,
  "category": "unknown",
  "confidence": 0,
  "reason": "short reason"
}
PROMPT;

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown. No explanation outside JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            return [
                'official' => false,
                'category' => 'unknown',
                'confidence' => 0,
                'reason' => 'The AI source verification returned no content.',
            ];
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result)) {
            return [
                'official' => false,
                'category' => 'unknown',
                'confidence' => 0,
                'reason' => 'The AI source verification returned invalid JSON.',
            ];
        }

        $official = (bool) ($result['official'] ?? false);
        $category = (string) ($result['category'] ?? 'unknown');
        $confidence = max(0, min(100, (int) ($result['confidence'] ?? 0)));
        $reason = (string) ($result['reason'] ?? 'No reason provided.');

        $allowedCategories = [
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

        if (!in_array($category, $allowedCategories, true)) {
            $category = 'unknown';
        }

        $reasonLower = strtolower($reason);

        if (
            !$official &&
            in_array($category, ['government', 'ministry', 'public_authority', 'company', 'club', 'federation', 'organization'], true) &&
            str_contains($reasonLower, 'post is about')
        ) {
            $official = true;
            $confidence = max($confidence, 80);
            $reason .= ' Auto-corrected because the category is an official-source category and the reason confirms the post concerns the organization itself.';
        }

        if (
            !$official &&
            (
                str_contains($combinedName, 'ministere') ||
                str_contains($combinedName, 'ministère') ||
                str_contains($combinedName, 'ministry') ||
                str_contains($combinedName, 'gouvernement') ||
                str_contains($combinedName, 'government') ||
                str_contains($combinedName, 'page officielle')
            )
        ) {
            $official = true;
            $category = 'ministry';
            $confidence = max($confidence, 90);
            $reason .= ' Auto-corrected because the page name clearly indicates an official government or ministry page.';
        }

        return [
            'official' => $official,
            'category' => $category,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}