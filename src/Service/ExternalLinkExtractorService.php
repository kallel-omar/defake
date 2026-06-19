<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExternalLinkExtractorService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function extract(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
            ]);

            $html = $response->getContent();

            preg_match('/<title>(.*?)<\/title>/is', $html, $titleMatch);

            $title = trim(
                html_entity_decode(
                    strip_tags($titleMatch[1] ?? '')
                )
            );

            $text = strip_tags($html);

            $text = preg_replace('/\s+/', ' ', $text);

            return [
                'title' => $title,
                'content' => mb_substr(trim($text), 0, 5000),
            ];
        } catch (\Throwable) {
            return [
                'title' => '',
                'content' => '',
            ];
        }
    }
}