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

Your job is to decide if the post contains a verifiable factual claim.

Return JSON only with this format:

{
  "containsClaim": true,
  "type": "news",
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
- mixed
- unknown

Rules:
- Opinion posts are not fake news.
- Personal feelings are not fake news.
- Questions are not fake news unless they include a factual claim.
- Rumors or accusations can be verifiable.
- Mixed posts should contain factual claim = true.

- If the post contains at least one factual statement that can be checked, set containsClaim to true.
- A post can be personal or casual and still contain a verifiable factual claim.
- Do not reject a post only because it is about sports, clubs, celebrations, family, or public events.
- If uncertain, prefer containsClaim = true and type = mixed.

- The title must describe what the post is about.
- The summary must explain the content clearly for a normal user.

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