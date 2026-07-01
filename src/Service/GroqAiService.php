<?php

namespace App\Service;

use App\Exception\AnalysisConfigurationException;
use App\Exception\AnalysisPermanentException;
use App\Exception\AnalysisTransientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

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
    if (trim($this->groqApiKey) === '' || trim($this->groqModel) === '') {
        throw new AnalysisConfigurationException(
            'Groq API key or model is missing.',
            'AI analysis is not configured correctly. Please try again later.'
        );
    }

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
                throw new AnalysisTransientException(
                    'Groq returned invalid JSON response.',
                    'The AI provider returned an invalid response. DeFake will retry shortly.'
                );
            }

            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            if ($content === '') {
                throw new AnalysisTransientException(
                    'Groq returned an empty response.',
                    'The AI provider returned an empty response. DeFake will retry shortly.'
                );
            }

            return $content;
        }

        $message = 'HTTP ' . $statusCode . ': ' . mb_substr($body, 0, 1000);

        if (in_array($statusCode, [408, 409, 425, 429, 500, 502, 503, 504], true)) {
            throw new AnalysisTransientException(
                'Groq temporary API error: ' . $message,
                'The AI provider is temporarily unavailable. DeFake will retry shortly.',
                retryDelay: $this->getRetryDelayMilliseconds($body)
            );
        }

        if (in_array($statusCode, [401, 403], true)) {
            throw new AnalysisConfigurationException(
                'Groq authentication failed: ' . $message,
                'AI analysis is not configured correctly. Please try again later.'
            );
        }

        throw new AnalysisPermanentException(
            'Groq API rejected the request: ' . $message,
            'The AI provider rejected this request. Please try again with different content.'
        );
    } catch (TransportExceptionInterface $e) {
        throw new AnalysisTransientException(
            'Groq connection failed: ' . $e->getMessage(),
            'The AI provider could not be reached. DeFake will retry shortly.',
            previous: $e,
            retryDelay: $this->getRetryDelayMilliseconds($e->getMessage())
        );
    }
}
private function getRetryDelayMilliseconds(string $message): ?int
{
    $message = mb_strtolower($message);

    if (preg_match('/try again in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $matches) === 1) {
        $seconds = (float) $matches[1];

        return min(30000, max(2000, (int) ceil($seconds * 1000) + 1000));
    }

    if (preg_match('/retry-after:\s*([0-9]+)/i', $message, $matches) === 1) {
        return min(30000, max(2000, ((int) $matches[1] * 1000) + 1000));
    }

    return null;
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
            throw new AnalysisTransientException(
                'Groq returned empty response.',
                'The AI provider returned an empty response. DeFake will retry shortly.'
            );
        }

        $decoded = $this->decodeJson($content);

        if (!is_array($decoded)) {
            throw new AnalysisTransientException(
                'Groq returned invalid JSON: ' . $content,
                'The AI provider returned an invalid response. DeFake will retry shortly.'
            );
        }

        return $decoded;
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
