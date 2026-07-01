<?php

declare(strict_types=1);

namespace App\Service;

final class ScoreCalculator04B
{
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
}