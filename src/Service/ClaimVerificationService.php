<?php

namespace App\Service;

class ClaimVerificationService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function verify(string $claim, string $evidence): array
    {
        $prompt = <<<PROMPT
Verify the factual claim using the provided evidence.

Claim:
$claim

Evidence:
$evidence

Important verification rules:

- Verify the exact claim, not just matching keywords.
- Do not assume a claim is true because the same numbers appear in the evidence.
- Check whether the evidence actually supports the full statement.
- If the evidence is unrelated, weak, ambiguous, or only partially matches, use INSUFFICIENT_EVIDENCE.
- Be conservative. When unsure, prefer INSUFFICIENT_EVIDENCE.

Return ONLY valid JSON.

Score rules:
- 90-100 = strong supporting evidence
- 70-89 = moderate supporting evidence
- 40-69 = mixed or uncertain evidence
- 10-39 = weak evidence
- 0-9 = no evidence

Explanation rules:
- Must contain at least one complete sentence.
- Explain WHY the verdict was chosen.
- Mention whether the evidence supports, contradicts, or fails to verify the claim.

JSON format:

{
  "verdict": "SUPPORTED",
  "score": 95,
  "explanation": "Multiple reliable sources confirm the claim."
}

Allowed verdict values:
- SUPPORTED
- CONTRADICTED
- INSUFFICIENT_EVIDENCE
PROMPT;

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'You are a professional fact-checking assistant. Return only valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            return [
                'verdict' => 'INSUFFICIENT_EVIDENCE',
                'score' => 0,
                'explanation' => 'The AI returned no verification result.',
            ];
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result)) {
            return [
                'verdict' => 'INSUFFICIENT_EVIDENCE',
                'score' => 0,
                'explanation' => 'The AI returned invalid JSON.',
            ];
        }

        return [
            'verdict' => $result['verdict'] ?? 'INSUFFICIENT_EVIDENCE',
            'score' => max(0, min(100, (int) ($result['score'] ?? 0))),
            'explanation' => $result['explanation'] ?? '',
        ];
    }
}