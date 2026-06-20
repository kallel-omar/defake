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

Examples:
- "Espérance Sportive de Tunis" posting about an Espérance player signing = official true, category club
- "Fédération Tunisienne de Football" posting about national team or federation decisions = official true, category federation
- "Ministère de la Santé" posting about health policy = official true, category ministry
- "Apple" posting about an Apple product = official true, category company
- "Taraji News" posting about Espérance = official false, category media
- "Esperance Fans" posting about Espérance = official false, category fan_page
- "Tunisie Sport" posting about a club = official false, category media
- "A journalist page" posting about a ministry = official false, category journalist

Facebook source context:
$sourceText
Facebook post text:
$postText
Return ONLY valid JSON with this exact structure:

{
  "official": false,
  "category": "government|ministry|public_authority|company|club|federation|organization|media|fan_page|journalist|commentary|unknown",
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
        $$content = trim($content);

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

if (
    !$official &&
    in_array($category, ['government', 'ministry', 'public_authority', 'company', 'club', 'federation', 'organization'], true) &&
    str_contains(strtolower($reason), 'post is about')
) {
    $official = true;
    $confidence = max($confidence, 80);
    $reason .= ' Auto-corrected because the category is an official-source category and the reason confirms the post concerns the organization itself.';
}

return [
    'official' => $official,
    'category' => $category,
    'confidence' => $confidence,
    'reason' => $reason,
];

        return [
            'official' => (bool) ($result['official'] ?? false),
            'category' => (string) ($result['category'] ?? 'unknown'),
            'confidence' => max(0, min(100, (int) ($result['confidence'] ?? 0))),
            'reason' => (string) ($result['reason'] ?? 'No reason provided.'),
        ];
    }
}