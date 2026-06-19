<?php

namespace App\Service;

class ClaimExtractionService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function extract(string $postText): array
    {
        $prompt = <<<PROMPT
Extract up to 3 important verifiable factual claims from the Facebook post.
Return fewer than 3 claims if the post contains fewer than 3 real claims.

Rules:
- Return between 0 and 3 claims.
- Never force 3 claims.
- Ignore opinions.
- Ignore insults.
- Ignore jokes.
- Ignore emotions.
- Ignore predictions.
- Ignore questions.
- Preserve important entities exactly as written.
- Preserve dates, percentages, monetary amounts, and locations.
- Preserve organization names such as ministries, federations, companies, clubs, and public institutions.
- Do not replace specific entities with generic terms.
- Ignore attribution statements such as "Reuters reported", "BBC reported", "According to...", "Sources said..."
- Extract the underlying factual claim instead.
- Do not create claims about the reporting source.
- Do not return duplicate claims.
- If two claims describe the same fact, keep only the most complete version.
- Do not invent facts that are not explicitly stated in the post.
- Keep only factual statements that can be verified.

Return ONLY valid JSON:

{
  "claims": [
    "claim 1",
    "claim 2",
    "claim 3"
  ]
}
PROMPT;

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt . "\n\nPost:\n" . $postText,
            ],
        ]);

        if (!$content) {
            return [];
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result)) {
            return [];
        }

        return array_values(
            array_filter($result['claims'] ?? [])
        );
    }
}