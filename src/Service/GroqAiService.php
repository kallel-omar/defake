<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GroqAiService
{
    public function __construct(
        private readonly string $groqApiKey,
        private readonly string $groqModel,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

   public function ask(array $messages, int $maxTokens = 800): ?string
{
    $maxAttempts = 3;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $response = $this->httpClient->request(
                'POST',
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->groqApiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->groqModel,
                        'messages' => $messages,
                        'temperature' => 0,
                        'max_tokens' => $maxTokens,
                        'response_format' => [
                            'type' => 'json_object',
                        ],
                    ],
                    'timeout' => 60,
                    'max_duration' => 75,
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    throw new \RuntimeException('Groq returned invalid JSON response.');
                }

                $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

                if ($content === '') {
                    throw new \RuntimeException('Groq returned an empty response.');
                }

                return $content;
            }

            $message = 'HTTP ' . $statusCode . ': ' . mb_substr($body, 0, 1000);
            $lowerMessage = mb_strtolower($message);

            $isRetryable =
                in_array($statusCode, [408, 409, 425, 429, 500, 502, 503, 504], true)
                || str_contains($lowerMessage, 'rate limit')
                || str_contains($lowerMessage, 'try again')
                || str_contains($lowerMessage, 'temporarily')
                || str_contains($lowerMessage, 'overloaded')
                || str_contains($lowerMessage, 'connection');

            if ($isRetryable && $attempt < $maxAttempts) {
                sleep(3 * $attempt);
                continue;
            }

            throw new \RuntimeException('Groq API error: ' . $message);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $lastError = $e->getMessage();

            if ($attempt < $maxAttempts) {
                sleep(3 * $attempt);
                continue;
            }

            throw new \RuntimeException(
                'Groq connection failed after ' . $maxAttempts . ' attempts: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    throw new \RuntimeException('Groq failed after retrying. Last error: ' . ($lastError ?? 'unknown error'));
}
    public function askJson(string $prompt, int $maxTokens = 1000): array
    {
        $content = $this->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown. No explanation outside JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ], $maxTokens);

        if (!$content) {
            throw new \RuntimeException('Groq returned empty response.');
        }

        $decoded = $this->decodeJson($content);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Groq returned invalid JSON: ' . $content);
        }

        return $decoded;
    }

    public function analyzeEvidence(string $claim, array $evidence): array
    {
        $evidenceJson = json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        $prompt = <<<PROMPT
You are a credibility-checking assistant for DeFake.

Analyze whether the evidence supports the claim.

Return only valid JSON with this exact structure:
{
  "verdict": "TRUE|MISLEADING|FALSE|NOT_ENOUGH_EVIDENCE",
  "confidence": 0,
  "explanation": "short explanation"
}

Claim:
{$claim}

Evidence:
{$evidenceJson}
PROMPT;

        $data = $this->askJson($prompt, 1200);

        $allowedVerdicts = [
            'TRUE',
            'MISLEADING',
            'FALSE',
            'NOT_ENOUGH_EVIDENCE',
        ];

        $verdict = strtoupper((string) ($data['verdict'] ?? 'NOT_ENOUGH_EVIDENCE'));

        if (!in_array($verdict, $allowedVerdicts, true)) {
            $verdict = 'NOT_ENOUGH_EVIDENCE';
        }

        return [
            'verdict' => $verdict,
            'confidence' => max(0, min(100, (int) ($data['confidence'] ?? 0))),
            'explanation' => trim((string) ($data['explanation'] ?? 'Groq analyzed the available evidence.')),
        ];
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