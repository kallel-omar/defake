<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApifyFacebookExtractorService
{
    public const PRIVATE_POST = '__PRIVATE_POST__';

    public function __construct(
        private readonly string $apifyApiToken,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function extract(string $url): array
    {
       $response = $this->httpClient->request(
    'POST',
    'https://api.apify.com/v2/acts/apify~facebook-posts-scraper/run-sync-get-dataset-items',
    [
        'headers' => [
            'Authorization' => 'Bearer ' . $this->apifyApiToken,
        ],
        'json' => [
            'captionText' => false,
            'resultsLimit' => 1,
            'startUrls' => [
                ['url' => $url],
            ],
        ],
        'timeout' => 300,
    ]
);

        $items = $response->toArray(false);

        if (!isset($items[0])) {
            return [
                'status' => 'failed',
                'text' => null,
                'images' => [],
                'links' => [],
                'raw' => null,
            ];
        }

        $item = $items[0];


        if (!is_array($item)) {
            return [
                'status' => 'failed',
                'text' => null,
                'images' => [],
                'links' => [],
                'raw' => $item,
            ];
        }

        $error = $item['error'] ?? null;

        if ($error === 'not_available') {
            return [
                'status' => self::PRIVATE_POST,
                'text' => null,
                'images' => [],
                'links' => [],
                'raw' => $item,
            ];
        }

        $text = $item['postText']
            ?? $item['text']
            ?? $item['caption']
            ?? null;

      return [
    'status' => 'ok',
    'text' => $text,
    'images' => $this->extractImages($item),
    'links' => $this->extractLinks($item, $text),
    'sourceContext' => [
        'pageName' => $item['pageName'] ?? null,
        'userName' => $item['user']['name'] ?? null,
        'userId' => $item['user']['id'] ?? null,
        'postUrl' => $item['url'] ?? null,
    ],
    'raw' => $item,
];
    }

    private function extractImages(array $item): array
    {
        $images = [];

        $possibleFields = [
            'image',
            'imageUrl',
            'photo',
            'photoUrl',
            'media',
            'mediaUrl',
            'attachments',
            'images',
        ];

        foreach ($possibleFields as $field) {
            if (!isset($item[$field])) {
                continue;
            }

            $value = $item[$field];

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $images[] = $value;
            }

            if (is_array($value)) {
                $images = array_merge($images, $this->findUrlsInArray($value));
            }
        }

        return array_values(array_unique($images));
    }

    private function extractLinks(array $item, ?string $text): array
    {
        $links = [];

        $possibleFields = [
            'url',
            'link',
            'externalUrl',
            'website',
            'attachments',
        ];

        foreach ($possibleFields as $field) {
            if (!isset($item[$field])) {
                continue;
            }

            $value = $item[$field];

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $links[] = $value;
            }

            if (is_array($value)) {
                $links = array_merge($links, $this->findUrlsInArray($value));
            }
        }

        if ($text) {
            preg_match_all('/https?:\/\/[^\s]+/i', $text, $matches);

            foreach ($matches[0] ?? [] as $foundUrl) {
                $links[] = rtrim($foundUrl, '.,)');
            }
        }

        return array_values(array_unique($links));
    }

    private function findUrlsInArray(array $data): array
    {
        $urls = [];

        foreach ($data as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $urls[] = $value;
            }

            if (is_array($value)) {
                $urls = array_merge($urls, $this->findUrlsInArray($value));
            }
        }

        return $urls;
    }
}