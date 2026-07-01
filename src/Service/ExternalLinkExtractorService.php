<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ExternalLinkExtractorService
{
    private const MAX_REDIRECTS = 3;
    private const MAX_RESPONSE_BYTES = 524288;
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const ALLOWED_CONTENT_TYPES = [
        'text/html',
        'text/plain',
        'application/xhtml+xml',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function extract(string $url): array
    {
        try {
            $response = $this->fetchSafeResponse($url);

            if (!$response) {
                return [
                    'title' => '',
                    'content' => '',
                ];
            }

            $html = $this->readLimitedContent($response);

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

    private function fetchSafeResponse(string $url): ?ResponseInterface
    {
        $currentUrl = trim($url);

        for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
            if (!$this->isSafeUrl($currentUrl)) {
                return null;
            }

            $response = $this->httpClient->request('GET', $currentUrl, [
                'headers' => [
                    'Accept' => 'text/html, text/plain;q=0.9, application/xhtml+xml;q=0.8',
                    'User-Agent' => 'DeFakeLinkPreview/1.0',
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
                'max_connect_duration' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);

            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $headers['location'][0] ?? null;

                if (!is_string($location) || trim($location) === '') {
                    return null;
                }

                $redirectUrl = $this->resolveRedirectUrl($currentUrl, $location);

                if ($redirectUrl === null) {
                    return null;
                }

                $currentUrl = $redirectUrl;
                continue;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }

            if (!$this->hasAllowedContentType($headers)) {
                return null;
            }

            return $response;
        }

        return null;
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/\s/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true) || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($port !== null && !in_array((int) $port, [80, 443], true)) {
            return false;
        }

        $host = $this->normalizeHost($host);

        if ($host === '' || $this->isBlockedHostName($host)) {
            return false;
        }

        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host, " \t\n\r\0\x0B.");

        if (function_exists('idn_to_ascii')) {
            $asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($asciiHost)) {
                $host = $asciiHost;
            }
        }

        return strtolower($host);
    }

    private function isBlockedHostName(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.localdomain')) {
            return true;
        }

        if ($host === 'metadata.google.internal' || str_ends_with($host, '.internal')) {
            return true;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return true;
        }

        return str_contains($host, '..');
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $ips = array_merge($ips, $ipv4Addresses);
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function hasAllowedContentType(array $headers): bool
    {
        $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));

        if ($contentType === '') {
            return true;
        }

        foreach (self::ALLOWED_CONTENT_TYPES as $allowedContentType) {
            if (str_starts_with($contentType, $allowedContentType)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }

        $currentParts = parse_url($currentUrl);

        if (!is_array($currentParts) || empty($currentParts['scheme']) || empty($currentParts['host'])) {
            return null;
        }

        $scheme = $currentParts['scheme'];
        $host = $currentParts['host'];
        $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $basePath = $currentParts['path'] ?? '/';
        $baseDirectory = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';

        return $scheme . '://' . $host . $port . $baseDirectory . $location;
    }

    private function readLimitedContent(ResponseInterface $response): string
    {
        $content = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isFirst()) {
                continue;
            }

            $content .= $chunk->getContent();

            if (strlen($content) >= self::MAX_RESPONSE_BYTES) {
                $response->cancel();

                return substr($content, 0, self::MAX_RESPONSE_BYTES);
            }
        }

        return $content;
    }
}
