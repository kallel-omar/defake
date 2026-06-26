<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiAiService
{
    public function __construct(
        private readonly string $geminiApiKey,
        private readonly string $geminiModel,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function ask(array $messages): ?string
    {
        $prompt = '';

        foreach ($messages as $message) {
            $role = strtoupper((string) ($message['role'] ?? 'user'));
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if ($role === 'SYSTEM') {
                $prompt .= "SYSTEM INSTRUCTION:\n{$content}\n\n";
            } else {
                $prompt .= "{$role}:\n{$content}\n\n";
            }
        }

        return $this->generateContent($prompt, 1000);
    }

    public function askJson(string $prompt, int $maxOutputTokens = 1000): array
    {
        $text = $this->generateContent($prompt, $maxOutputTokens);

        $decoded = $this->decodeJson($text);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini returned invalid JSON.');
        }

        return $decoded;
    }

    public function analyzeEvidence(string $claim, array $evidence): array
    {
        $prompt = <<<PROMPT
You are a fact-checking assistant.

Analyze the claim using ONLY the evidence below.

Return ONLY valid JSON with this structure:
{
  "verdict": "TRUE | FALSE | MISLEADING | NOT_ENOUGH_EVIDENCE",
  "confidence": 0,
  "explanation": "short explanation",
  "supporting_sources": [],
  "contradicting_sources": []
}

Claim:
{$claim}

Evidence:
{$this->formatEvidence($evidence)}
PROMPT;

        $text = $this->generateContent($prompt, 1200);

        $decoded = $this->decodeJson($text);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini returned invalid evidence-analysis JSON.');
        }

        return $decoded;
    }

    private function generateContent(string $prompt, int $maxOutputTokens = 1000): string
    {
        $attempts = 0;
        $maxAttempts = 3;

        do {
            $attempts++;

            $response = $this->httpClient->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModel . ':generateContent?key=' . $this->geminiApiKey,
                [
                    'json' => [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => $maxOutputTokens,
                            'response_mime_type' => 'application/json',
                        ],
                    ],
                    'timeout' => 60,
                ]
            );

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                $code = (int) ($data['error']['code'] ?? 0);
                $message = (string) ($data['error']['message'] ?? 'Unknown Gemini error');

                if ($code === 503 && $attempts < $maxAttempts) {
                    sleep($attempts * 2);
                    continue;
                }

                throw new \RuntimeException('Gemini API error ' . $code . ': ' . $message);
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$text) {
                throw new \RuntimeException('Gemini returned an empty response.');
            }

            return $text;
        } while ($attempts < $maxAttempts);

        throw new \RuntimeException('Gemini failed after retrying.');
    }

    private function formatEvidence(array $evidence): string
    {
        return json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
}