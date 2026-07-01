<?php

declare(strict_types=1);

namespace App\Service;

final class EvidenceFormatterService
{
    public function __construct(
        private readonly SourceConfidenceService $sourceConfidenceService,
        private readonly OfficialSourceDetectorService $officialSourceDetectorService,
    ) {
    }

    public function formatSources(array $items, ?string $claim = null, array $relevantIndexes = []): array
    {
        $sources = [];

        $relevantIndexes = array_values(array_unique(array_map('intval', $relevantIndexes)));

        if (empty($relevantIndexes)) {
            return [];
        }

        foreach (array_slice($items, 0, 5, true) as $index => $item) {
            if (!in_array((int) $index, $relevantIndexes, true)) {
                continue;
            }

            $link = $item['link'] ?? null;

            if (!$link) {
                continue;
            }

            $title = $item['title'] ?? 'No title';
            $snippet = $item['snippet'] ?? '';
            $sourceName = $item['source'] ?? parse_url($link, PHP_URL_HOST);

            $confidence = $this->sourceConfidenceService->score($link);

            $officialDecision = $this->officialSourceDetectorService->evaluateEvidenceUrl(
                $link,
                $title,
                $snippet,
                $claim ?? ''
            );

            if (($confidence['type'] ?? 'unknown') === 'social') {
                if (!$officialDecision['official'] || ($officialDecision['confidence'] ?? 0) < 65) {
                    continue;
                }
            } else {
                if (($confidence['score'] ?? 0) < 60) {
                    continue;
                }
            }

            $sources[] = [
                'title' => $title,
                'link' => $link,
                'snippet' => $snippet,
                'source' => $sourceName,
                'confidenceScore' => $confidence['score'] ?? 0,
                'confidenceLabel' => $confidence['label'] ?? 'Unknown',
                'officialCategory' => $officialDecision['category'] ?? 'unknown',
                'officialConfidence' => $officialDecision['confidence'] ?? 0,
                'officialReason' => $officialDecision['reason'] ?? '',
            ];
        }

        return $sources;
    }
}