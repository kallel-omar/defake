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
        return $this->formatSourcesInternal($items, $claim, $relevantIndexes)['sources'];
    }

    public function formatSourcesWithDebug(array $items, ?string $claim = null, array $relevantIndexes = []): array
    {
        return $this->formatSourcesInternal($items, $claim, $relevantIndexes);
    }

    private function formatSourcesInternal(array $items, ?string $claim = null, array $relevantIndexes = []): array
    {
        $sources = [];
        $debug = [
            'relevantIndexes' => array_values(array_unique(array_map('intval', $relevantIndexes))),
            'acceptedSources' => [],
            'rejectedSources' => [],
        ];

        $relevantIndexes = $debug['relevantIndexes'];

        if (empty($relevantIndexes)) {
            return [
                'sources' => [],
                'debug' => $debug,
            ];
        }

        foreach (array_slice($items, 0, 5, true) as $index => $item) {
            if (!in_array((int) $index, $relevantIndexes, true)) {
                continue;
            }

            $link = $item['link'] ?? null;
            $title = $item['title'] ?? 'No title';
            $snippet = $item['snippet'] ?? '';
            $sourceName = $item['source'] ?? ($link ? parse_url($link, PHP_URL_HOST) : null);

            if (!$link) {
                $debug['rejectedSources'][] = [
                    'index' => (int) $index,
                    'title' => $title,
                    'link' => null,
                    'source' => $sourceName,
                    'reason' => 'missing_link',
                ];

                continue;
            }

            $confidence = $this->sourceConfidenceService->score($link);

            $officialDecision = $this->officialSourceDetectorService->evaluateEvidenceUrl(
                $link,
                $title,
                $snippet,
                $claim ?? ''
            );

            if (($confidence['type'] ?? 'unknown') === 'social') {
                if (!$officialDecision['official'] || ($officialDecision['confidence'] ?? 0) < 65) {
                    $debug['rejectedSources'][] = [
                        'index' => (int) $index,
                        'title' => $title,
                        'link' => $link,
                        'source' => $sourceName,
                        'reason' => 'social_source_not_official_enough',
                        'confidenceScore' => $confidence['score'] ?? 0,
                        'confidenceLabel' => $confidence['label'] ?? 'Unknown',
                        'confidenceType' => $confidence['type'] ?? 'unknown',
                        'officialCategory' => $officialDecision['category'] ?? 'unknown',
                        'officialConfidence' => $officialDecision['confidence'] ?? 0,
                        'requiredOfficialConfidence' => 65,
                        'officialReason' => $officialDecision['reason'] ?? '',
                    ];

                    continue;
                }
            } else {
                if (($confidence['score'] ?? 0) < 60) {
                    $debug['rejectedSources'][] = [
                        'index' => (int) $index,
                        'title' => $title,
                        'link' => $link,
                        'source' => $sourceName,
                        'reason' => 'source_confidence_below_display_threshold',
                        'confidenceScore' => $confidence['score'] ?? 0,
                        'requiredConfidenceScore' => 60,
                        'confidenceLabel' => $confidence['label'] ?? 'Unknown',
                        'confidenceType' => $confidence['type'] ?? 'unknown',
                        'officialCategory' => $officialDecision['category'] ?? 'unknown',
                        'officialConfidence' => $officialDecision['confidence'] ?? 0,
                        'officialReason' => $officialDecision['reason'] ?? '',
                    ];

                    continue;
                }
            }

            $source = [
                'title' => $title,
                'link' => $link,
                'snippet' => $snippet,
                'source' => $sourceName,
                'confidenceScore' => $confidence['score'] ?? 0,
                'confidenceLabel' => $confidence['label'] ?? 'Unknown',
                'officialCategory' => $officialDecision['category'] ?? 'unknown',
                'officialConfidence' => $officialDecision['confidence'] ?? 0,
                'officialReason' => $officialDecision['reason'] ?? '',
                'confidenceType' => $confidence['type'] ?? 'unknown',
            ];

            $sources[] = $source;
            $debug['acceptedSources'][] = $source;
        }

        return [
            'sources' => $sources,
            'debug' => $debug,
        ];
    }
}