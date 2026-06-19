<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GroqAiService
{
    public function __construct(
        private readonly string $groqApiKey,
        private readonly string $groqModel,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function ask(array $messages): ?string
    {
        $response = $this->httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->groqApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->groqModel,
                'messages' => $messages,
                'temperature' => 0,
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);

        return $data['choices'][0]['message']['content'] ?? null;
    }
}