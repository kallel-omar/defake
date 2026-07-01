<?php

declare(strict_types=1);

namespace App\Service;

final class ScoreBreakdownBuilder
{
    public function build(
        int $evidenceMatch,
        int $sourceAuthority,
        int $sourceIndependence,
        int $riskSafety,
        array $reasons = []
    ): array {
        $evidenceMatch = max(0, min(50, $evidenceMatch));
        $sourceAuthority = max(0, min(25, $sourceAuthority));
        $sourceIndependence = max(0, min(15, $sourceIndependence));
        $riskSafety = max(0, min(10, $riskSafety));

        return [
            'evidenceMatch' => [
                'score' => $evidenceMatch,
                'max' => 50,
                'reason' => $reasons['evidenceMatch'] ?? '',
            ],
            'sourceAuthority' => [
                'score' => $sourceAuthority,
                'max' => 25,
                'reason' => $reasons['sourceAuthority'] ?? '',
            ],
            'sourceIndependence' => [
                'score' => $sourceIndependence,
                'max' => 15,
                'reason' => $reasons['sourceIndependence'] ?? '',
            ],
            'riskSafety' => [
                'score' => $riskSafety,
                'max' => 10,
                'reason' => $reasons['riskSafety'] ?? '',
            ],
            'total' => [
                'score' => $evidenceMatch + $sourceAuthority + $sourceIndependence + $riskSafety,
                'max' => 100,
            ],
        ];
    }
}