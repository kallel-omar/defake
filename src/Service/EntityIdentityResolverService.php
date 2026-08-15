<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EntityIdentityResolverService
{
    private const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $firstName, string $secondName): array
    {
        $firstName = trim($firstName);
        $secondName = trim($secondName);

        if ($firstName === '' || $secondName === '') {
            return $this->result(
                false,
                'missing_name',
                null,
                'One of the entity names is missing.'
            );
        }

        $normalizedFirst = $this->normalize($firstName);
        $normalizedSecond = $this->normalize($secondName);

        // Fast deterministic match when both names are already equivalent.
        if (
            $normalizedFirst !== ''
            && $normalizedFirst === $normalizedSecond
        ) {
            return $this->result(
                true,
                'normalized_exact_match',
                null,
                'Both entity names are equivalent after normalization.'
            );
        }

        try {
            $firstCandidates = $this->searchWikidata($firstName);
            $secondCandidates = $this->searchWikidata($secondName);
        } catch (\Throwable $e) {
            // Entity resolution must never break the whole DeFake analysis.
            $this->logger->warning('Entity identity resolution failed.', [
                'firstName' => $firstName,
                'secondName' => $secondName,
                'exception' => $e,
            ]);

            return $this->result(
                false,
                'resolver_unavailable',
                null,
                'External entity identity resolution was unavailable.'
            );
        }

        if ($firstCandidates === [] || $secondCandidates === []) {
            return $this->result(
                false,
                'wikidata_not_found',
                null,
                'Wikidata could not resolve both entity names.'
            );
        }

        // Production safety:
        // only accept the multilingual identity match when Wikidata ranks
        // the SAME entity as the best candidate for both names.
        $firstBest = $firstCandidates[0];
        $secondBest = $secondCandidates[0];

        if ($firstBest['id'] === $secondBest['id']) {
            return $this->result(
                true,
                'wikidata_same_entity',
                $firstBest['id'],
                sprintf(
                    'Both names resolve to the same Wikidata entity %s.',
                    $firstBest['id']
                )
            );
        }

        return $this->result(
            false,
            'wikidata_different_entities',
            null,
            'The best Wikidata candidates for the two names are different.'
        );
    }

    private function searchWikidata(string $name): array
    {
        foreach ($this->preferredLanguages($name) as $language) {
            $response = $this->httpClient->request(
                'GET',
                self::WIKIDATA_API,
                [
                    'query' => [
                        'action' => 'wbsearchentities',
                        'format' => 'json',
                        'search' => $name,
                        'language' => $language,
                        'uselang' => $language,
                        'type' => 'item',
                        'limit' => 3,
                    ],
                    'headers' => [
                        'User-Agent' => 'DeFake/1.0 credibility-checking-app',
                    ],
                    'timeout' => 8,
                ]
            );

            if ($response->getStatusCode() >= 400) {
                continue;
            }

            $data = $response->toArray(false);
            $search = $data['search'] ?? [];

            if (!is_array($search) || $search === []) {
                continue;
            }

            $candidates = [];

            foreach ($search as $item) {
                $id = trim((string) ($item['id'] ?? ''));

                if ($id === '') {
                    continue;
                }

                $candidates[] = [
                    'id' => $id,
                    'label' => trim((string) ($item['label'] ?? '')),
                    'description' => trim((string) ($item['description'] ?? '')),
                    'language' => $language,
                ];
            }

            if ($candidates !== []) {
                return $candidates;
            }
        }

        return [];
    }

    private function preferredLanguages(string $name): array
    {
        if (preg_match('/\p{Arabic}/u', $name) === 1) {
            return ['ar', 'fr', 'en'];
        }

        return ['fr', 'en', 'ar'];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize(
                $value,
                \Normalizer::FORM_KC
            );

            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        return (string) preg_replace(
            '/[^\p{L}\p{N}]+/u',
            '',
            $value
        );
    }

    private function result(
        bool $sameEntity,
        string $method,
        ?string $entityId,
        string $reason
    ): array {
        return [
            'sameEntity' => $sameEntity,
            'method' => $method,
            'entityId' => $entityId,
            'reason' => $reason,
        ];
    }
}