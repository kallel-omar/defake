<?php

namespace App\Service;

class PostClassifierService
{
    public function __construct(
       private readonly GroqAiService $groqAiService
    ) {
    }

    public function classify(string $postText): array
    {
       $prompt = <<<PROMPT
You are a Facebook post classifier for a fake news detection app.

Your job is to decide if the post contains a clearly verifiable factual claim.

Return JSON only with this format:

{
  "containsClaim": false,
  "type": "opinion",
  "title": "Short classification title",
  "summary": "One or two sentence summary of the post",
  "confidence": 90,
  "reason": "Short explanation"
}

Allowed types:
- news
- opinion
- personal_story
- advertisement
- question
- satire
- rumor
- insult
- sports_banter
- political_commentary
- mixed
- unknown

Important dialect rules:
- The post may be in Tunisian Arabic, Maghrebi Arabic, Arabic, French, or mixed language.
- Do not translate Tunisian slang, insults, sarcasm, or metaphors literally.
- Words like "طحين", "طحانة", "بارازيت", "بني كرغول" may be used as insults or political/sports slang, not literal factual claims.
- If the post mainly insults, mocks, attacks, or expresses anger without a specific checkable event, number, date, person action, official decision, transfer, match, law, death, arrest, or announcement, set containsClaim to false.
- Do not invent missing context.
- Do not convert insults into factual claims.

Claim rules:
- containsClaim must be true only if there is a specific factual claim that can be checked with sources.
- Rumors or accusations can be verifiable only if they mention a specific event, person, organization, decision, date, number, transfer, score, arrest, death, statement, or official action.
- Mixed posts should containClaim = true only when they include at least one specific checkable factual claim.
- If uncertain, prefer containsClaim = false.

Title and summary rules:
- If the post is mainly insult/opinion, title should say that clearly.
- Summary should not reinterpret slang literally.
- Summary should explain that the post is insulting/critical/emotional commentary if no factual claim exists.

Facebook post:
"""$postText"""
PROMPT;

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'You are a strict JSON classifier. Return only valid JSON. No markdown.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            throw new \Exception('Groq classifier returned no content.');
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim($content);

        $classification = json_decode($content, true);

        if (!is_array($classification)) {
            throw new \Exception('Invalid classifier JSON: ' . $content);
        }

        return [
            'containsClaim' => (bool) ($classification['containsClaim'] ?? false),
            'type' => $classification['type'] ?? 'unknown',
            'title' => $classification['title'] ?? 'Untitled post',
            'summary' => $classification['summary'] ?? 'No summary available.',
            'confidence' => (int) ($classification['confidence'] ?? 0),
            'reason' => $classification['reason'] ?? 'No reason provided.',
        ];
    }
}