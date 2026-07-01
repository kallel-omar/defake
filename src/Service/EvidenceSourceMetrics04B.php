<?php

declare(strict_types=1);

namespace App\Service;

final class EvidenceSourceMetrics04B
{
    public function selectRelevantItems(array $evidenceItems, array $relevantIndexes = []): array
    {
        if (empty($relevantIndexes)) {
            return $evidenceItems;
        }

        $selected = [];

        foreach ($relevantIndexes as $index) {
            if (isset($evidenceItems[$index])) {
                $selected[] = $evidenceItems[$index];
            }
        }

        return $selected;
    }

    public function extractHost(array $item): string
    {
        $link = (string) ($item['link'] ?? '');

        if ($link !== '') {
            $host = parse_url($link, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return preg_replace('/^www\./', '', mb_strtolower($host)) ?? mb_strtolower($host);
            }
        }

        return mb_strtolower(trim((string) ($item['source'] ?? '')));
    }
}