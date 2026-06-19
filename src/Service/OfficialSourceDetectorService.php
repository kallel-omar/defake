<?php

namespace App\Service;

class OfficialSourceDetectorService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function detect(array $sourceContext): array
    {
        if (empty($sourceContext)) {
            return [
                'official' => false,
                'reason' => 'No source context was available.',
            ];
        }

        $sourceText = json_encode($sourceContext, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are helping a fact-checking system evaluate a Facebook source.

Decide if this Facebook source is the OFFICIAL page of the organization itself.

Very strict rules:
- Return true ONLY if the page clearly represents the organization, club, company, ministry, federation, or public authority itself.
- Return false for news pages.
- Return false for sports media pages.
- Return false for fan pages.
- Return false for supporter pages.
- Return false for commentary pages.
- Return false for pages that only report about organizations.
- A professional-looking name does NOT mean official.
- A specific Facebook ID does NOT mean official.
- If uncertain, return false.

Examples:
- "Espérance Sportive de Tunis" posting about Espérance = true
- "Fédération Tunisienne de Football" = true
- "Sport by Tunisia 24" = false
- "Taraji News" = false
- "Tunisie Sport" = false
- "Football Tunisia" = false

Facebook source context:
$sourceText

Return ONLY valid JSON:

{
  "official": false,
  "reason": "short reason"
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
            return [
                'official' => false,
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
                'reason' => 'The AI source verification returned invalid JSON.',
            ];
        }

        return [
            'official' => (bool) ($result['official'] ?? false),
            'reason' => $result['reason'] ?? 'No reason provided.',
        ];
    }
}