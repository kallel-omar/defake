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
Extract the 3 most important verifiable factual claims from the Facebook post.

Rules:
- Return maximum 3 claims.
- Ignore opinions.
- Ignore insults.
- Ignore jokes.
- Ignore emotions.
- Ignore predictions.
- Ignore questions.
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