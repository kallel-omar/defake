<?php

declare(strict_types=1);

namespace App\Service;

final class ScoreCalculator04B

{
    public function __construct(
    private readonly EvidenceSourceMetrics04B $evidenceSourceMetrics04B,
) {
}
    public function calculateEvidenceMatchScore(
        array $evidenceDecision,
        bool $verificationContextSafe,
        array $formattedEvidenceSources = [],
        array $officialSource = []
    ): int {
        $status = strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN'));

        $hasUsableEvidenceSource = !empty($formattedEvidenceSources);
        $isOfficialSource = ($officialSource['official'] ?? false) === true;

        // Production safety:
        // DeFake should not give high evidence-match points if it cannot show
        // any usable evidence source, unless the original source itself is official.
        if (!$hasUsableEvidenceSource && !$isOfficialSource) {
            return match ($status) {
                'SUPPORTED' => 15,
                'PARTIALLY_SUPPORTED' => 10,
                'CONTRADICTED' => 5,
                default => 0,
            };
        }

        return match ($status) {
            // Evidence match should measure relation to the claim only.
            // Source strength is handled separately by Source Authority.
            'SUPPORTED' => $verificationContextSafe ? 42 : 15,

            'PARTIALLY_SUPPORTED' => 28,

            // Evidence exists but appears to be about a different context/topic.
            'UNRELATED' => 5,

            // No useful confirming evidence.
            'UNSUPPORTED' => 0,

            // Refutation is handled by verdict caps, but the support score stays very low.
            'CONTRADICTED' => 5,

            default => 0,
        };
    }

    public function calculateRiskSafetyScore(string $postText): int
    {
        $text = mb_strtolower($postText);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ة', 'ه', $text);

        $highRiskSignals = [
            'عاجل جدا',
            'تسريب خطير',
            'فضيحه',
            'كارثه',
            'زلزال',
            'الحقيقه ستظهر',
            'مصادر خاصه',
            'مصادر تؤكد',
            'breaking',
            'exclusive',
            'shocking',
            'leaked',
            'sources say',
            'rumeur',
            'urgent',
            'exclusif',
        ];

        $mediumRiskSignals = [
            'عاجل',
            'حصري',
            'قريبا',
            'قريباً',
            'في الساعات القادمه',
            'حسب مصادر',
            'يقال',
            'reportedly',
            'rumor',
            'rumour',
            'selon des sources',
        ];

        $highCount = 0;
        foreach ($highRiskSignals as $signal) {
            if (str_contains($text, $signal)) {
                $highCount++;
            }
        }

        $mediumCount = 0;
        foreach ($mediumRiskSignals as $signal) {
            if (str_contains($text, $signal)) {
                $mediumCount++;
            }
        }

        if ($highCount >= 2) {
            return 1;
        }

        if ($highCount === 1) {
            return 3;
        }

        if ($mediumCount >= 2) {
            return 5;
        }

        if ($mediumCount === 1) {
            return 7;
        }

        return 9;
    }
    public function calculateSourceAuthorityScore(
    array $officialSource,
    array $evidenceItems,
    array $relevantIndexes = []
): int {
    if (($officialSource['official'] ?? false) === true) {
        $confidence = (int) ($officialSource['confidence'] ?? 0);
        $category = (string) ($officialSource['category'] ?? 'unknown');

        if ($confidence >= 85 && in_array($category, [
            'government',
            'ministry',
            'public_authority',
            'company',
            'club',
            'federation',
            'organization',
        ], true)) {
            return 25;
        }

        return 20;
    }

    $relevantItems = $this->evidenceSourceMetrics04B->selectRelevantItems($evidenceItems, $relevantIndexes);

    if (empty($relevantItems)) {
        return 0;
    }

    $maxConfidence = 0;

    foreach ($relevantItems as $item) {
        $maxConfidence = max($maxConfidence, (int) ($item['confidenceScore'] ?? $item['sourceScore'] ?? 0));
    }

    return match (true) {
        $maxConfidence >= 90 => 23,
        $maxConfidence >= 75 => 18,
        $maxConfidence >= 60 => 14,
        $maxConfidence >= 40 => 9,
        $maxConfidence >= 20 => 5,
        default => 2,
    };
}

public function calculateSourceIndependenceScore(
    array $officialSource,
    array $evidenceItems,
    array $relevantIndexes = []
): int {
    $relevantItems = $this->evidenceSourceMetrics04B->selectRelevantItems($evidenceItems, $relevantIndexes);

    $hosts = [];

    foreach ($relevantItems as $item) {
        $host = $this->evidenceSourceMetrics04B->extractHost($item);

        if ($host !== '') {
            $hosts[$host] = true;
        }
    }

    $distinctSources = count($hosts);

    if (($officialSource['official'] ?? false) === true && $distinctSources >= 1) {
        return 12;
    }

    if (($officialSource['official'] ?? false) === true) {
        return 10;
    }

    $maxConfidence = 0;

    foreach ($relevantItems as $item) {
        $maxConfidence = max($maxConfidence, (int) ($item['confidenceScore'] ?? $item['sourceScore'] ?? 0));
    }

    return match (true) {
        $distinctSources >= 3 && $maxConfidence >= 75 => 14,
        $distinctSources >= 2 && $maxConfidence >= 75 => 12,
        $distinctSources >= 2 && $maxConfidence >= 50 => 9,
        $distinctSources === 1 && $maxConfidence >= 75 => 8,
        $distinctSources === 1 => 4,
        default => 0,
    };
}
}