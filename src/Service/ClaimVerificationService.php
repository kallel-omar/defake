<?php

namespace App\Service;

class ClaimVerificationService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function verify(string $claim, string $evidence, ?string $originalPostContext = null): array
    {
        $originalPostContext = trim($originalPostContext ?? 'Not provided');

        $prompt = <<<PROMPT
You are a professional fact-checking assistant.

Verify the factual claim using the provided evidence.

Claim:
$claim

Original post context:
$originalPostContext

Evidence:
$evidence

Important verification rules:

- Verify the exact claim, not just matching keywords.
- Compare the claim, original post context, and evidence context.
- Evidence must refer to the same real-world situation as the claim.
- Check whether the evidence matches the same main subject, location, organization, person, date/time, event, quantity/number, and action.
- If evidence confirms a similar claim but in a different context, set contextMatch to false.
- If the original post is vague and evidence adds a country, organization, person, club, company, institution, or event that was not present in the post, set contextMatch to false unless the connection is clearly proven.
- Do not import missing context from unrelated evidence.
- Do not assume a claim is true because the same keywords or numbers appear in the evidence.
- If the evidence is unrelated, weak, ambiguous, or only partially matches, use INSUFFICIENT_EVIDENCE.
- Be conservative. When unsure, prefer INSUFFICIENT_EVIDENCE.
- Decide if the original claim has enough context to identify the real-world situation.
- If the claim uses a generic subject like "the bank", "the club", "the player", "the company", "the ministry", "the government", "the president", "the coach", "the federation", or similar, the original post must clearly identify which one.
- If the original post does not identify the subject enough, set claimContextComplete to false.
- If the evidence introduces a specific subject, organization, country, person, place, event, or date that was not clearly present in the original claim/context, set evidenceAddsNewContext to true.
- If claimContextComplete is false and evidenceAddsNewContext is true, verdict must be INSUFFICIENT_EVIDENCE.

Strict verdict rules:

- Use SUPPORTED only when the evidence explicitly confirms the exact claim and contextMatch is true.
- If contextMatch is false, verdict must be INSUFFICIENT_EVIDENCE or CONTRADICTED, never SUPPORTED.
- If the evidence says "may", "likely", "expected", "rumor", "not confirmed", or is uncertain, use INSUFFICIENT_EVIDENCE.
- If the evidence is related but does not confirm the exact number, date, person, organization, location, event, or action, use INSUFFICIENT_EVIDENCE.
- If the evidence clearly says the claim is false or gives a different fact, use CONTRADICTED.
- Never mark uncertain or context-mismatched evidence as SUPPORTED.

Score rules:

- 90-100 = exact claim strongly confirmed in the same context
- 80-89 = exact claim moderately confirmed in the same context
- 40-79 = related but uncertain, incomplete, or context unclear
- 10-39 = weak evidence
- 0-9 = no useful evidence

Explanation rules:

- Must contain at least one complete sentence.
- Explain why the verdict was chosen.
- Mention whether the evidence supports, contradicts, or fails to verify the claim.
- If contextMatch is false, explain what context does not match.

Return ONLY valid JSON with this exact structure:

{
  "verdict": "INSUFFICIENT_EVIDENCE",
  "score": 50,
  "claimContextComplete": false,
  "evidenceAddsNewContext": true,
  "contextMatch": false,
  "contextReason": "The original claim is too vague, and the evidence adds specific context that was not clearly present in the original post.",
  "explanation": "The evidence is related, but it does not explicitly verify the exact claim in the same context."
}

Allowed verdict values:
- SUPPORTED
- CONTRADICTED
- INSUFFICIENT_EVIDENCE
PROMPT;
$content = $this->groqAiService->ask([
    [
        'role' => 'system',
        'content' => 'Return only valid JSON. No markdown. No text outside JSON.',
    ],
    [
        'role' => 'user',
        'content' => $prompt,
    ],
], 350);

        if (!$content) {
            return [
                'verdict' => 'INSUFFICIENT_EVIDENCE',
                'score' => 0,
                'contextMatch' => false,
                'contextReason' => 'The AI returned no verification result.',
                'explanation' => 'The AI returned no verification result.',
            ];
        }

        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result)) {
            return [
                'verdict' => 'INSUFFICIENT_EVIDENCE',
                'score' => 0,
                'contextMatch' => false,
                'contextReason' => 'The AI returned invalid JSON.',
                'explanation' => 'The AI returned invalid JSON.',
            ];
        }

        $verdict = strtoupper((string) ($result['verdict'] ?? 'INSUFFICIENT_EVIDENCE'));
$score = max(0, min(100, (int) ($result['score'] ?? 0)));

$claimContextComplete = filter_var($result['claimContextComplete'] ?? false, FILTER_VALIDATE_BOOLEAN);
$evidenceAddsNewContext = filter_var($result['evidenceAddsNewContext'] ?? true, FILTER_VALIDATE_BOOLEAN);
$contextMatch = filter_var($result['contextMatch'] ?? false, FILTER_VALIDATE_BOOLEAN);

$contextReason = trim((string) ($result['contextReason'] ?? ''));
$explanation = trim((string) ($result['explanation'] ?? ''));

        $allowedVerdicts = [
            'SUPPORTED',
            'CONTRADICTED',
            'INSUFFICIENT_EVIDENCE',
        ];

        if (!in_array($verdict, $allowedVerdicts, true)) {
            $verdict = 'INSUFFICIENT_EVIDENCE';
        }

        $lowerExplanation = mb_strtolower($explanation);

        $uncertainWords = [
            'uncertain',
            'does not explicitly confirm',
            'does not confirm',
            'not explicitly confirm',
            'fails to verify',
            'insufficient',
            'ambiguous',
            'weak',
            'may have',
            'might',
            'suggests',
            'potential',
            'likely',
            'expected',
            'not confirmed',
            'rumor',
            'rumour',
        ];

        foreach ($uncertainWords as $word) {
            if (str_contains($lowerExplanation, $word) && $verdict === 'SUPPORTED') {
                $verdict = 'INSUFFICIENT_EVIDENCE';
                $score = min($score, 55);
                break;
            }
        }
if (
    $verdict === 'SUPPORTED'
    && (
        $contextMatch === false
        || $claimContextComplete === false
        || $evidenceAddsNewContext === true
    )
) {
    $verdict = 'INSUFFICIENT_EVIDENCE';
    $score = min($score, 55);

    $safetyReason = 'The claim was not supported because the original context was incomplete or the evidence added new context not clearly present in the post.';

    if ($contextReason !== '') {
        $explanation = $contextReason . ' ' . $explanation;
    } else {
        $explanation = $safetyReason . ' ' . $explanation;
    }
}

        if ($verdict === 'SUPPORTED' && $score < 80) {
            $verdict = 'INSUFFICIENT_EVIDENCE';
            $score = min($score, 55);
        }

        if ($explanation === '') {
            $explanation = 'The evidence was reviewed, but the claim could not be clearly verified.';
        }

        if ($contextReason === '') {
            $contextReason = $contextMatch
                ? 'The evidence appears to match the claim context.'
                : 'The evidence context does not clearly match the original claim context.';
        }

        return [
    'verdict' => $verdict,
    'score' => $score,
    'claimContextComplete' => $claimContextComplete,
    'evidenceAddsNewContext' => $evidenceAddsNewContext,
    'contextMatch' => $contextMatch,
    'contextReason' => $contextReason,
    'explanation' => $explanation,
];
    }
}