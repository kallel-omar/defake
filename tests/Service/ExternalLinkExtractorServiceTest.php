<?php

namespace App\Tests\Service;

use App\Service\ExternalLinkExtractorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExternalLinkExtractorServiceTest extends TestCase
{
    public function testRejectsPrivateIpWithoutMakingHttpRequest(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::fail('Unsafe URLs must be rejected before making an HTTP request.');
        });

        $extractor = new ExternalLinkExtractorService($client);

        self::assertSame(
            ['title' => '', 'content' => ''],
            $extractor->extract('http://169.254.169.254/latest/meta-data')
        );
    }

    public function testRejectsRedirectToPrivateIp(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => [
                    'location' => 'http://127.0.0.1/admin',
                ],
            ]),
            function (string $method, string $url, array $options): MockResponse {
                self::fail('Unsafe redirect targets must be rejected before the redirected request.');
            },
        ]);

        $extractor = new ExternalLinkExtractorService($client);

        self::assertSame(
            ['title' => '', 'content' => ''],
            $extractor->extract('http://93.184.216.34/article')
        );
    }

    public function testExtractsTextFromSafeHtmlResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '<html><head><title>Example title</title></head><body> Hello world</body></html>',
            [
                'http_code' => 200,
                'response_headers' => [
                    'content-type' => 'text/html; charset=UTF-8',
                ],
            ]
        ));

        $extractor = new ExternalLinkExtractorService($client);

        self::assertSame(
            [
                'title' => 'Example title',
                'content' => 'Example title Hello world',
            ],
            $extractor->extract('http://93.184.216.34/article')
        );
    }
}
