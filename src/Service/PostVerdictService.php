<?php

namespace App\Service;
/**
 * Reserved for the future claim-based verification system.
 *
 * Planned flow:
 * Post
 * → Claim Extraction
 * → Claim Verification
 * → PostVerdictService
 * → Final Verdict
 *
 * Currently unused because DeFake uses PostAnalysisService
 * for full-post analysis.
 */
class PostVerdictService
{
    public function calculate(array $claimResults): array
    {
        $total = count($claimResults);

        if ($total === 0) {
            return [
                'score' => 0,
                'verdict' => 'NOT_VERIFIABLE',
                'explanation' => 'No verifiable claims were found.',
            ];
        }

        $scores = array_column($claimResults, 'score');
        $averageScore = (int) round(array_sum($scores) / $total);

        if ($averageScore >= 70) {
            $verdict = 'Likely Trusted';
        } elseif ($averageScore >= 40) {
            $verdict = 'Suspicious';
        } else {
            $verdict = 'Likely Fake';
        }

        $explanation = sprintf(
            'The final verdict is based on %d extracted claim(s), with an average verification score of %d/100.',
            $total,
            $averageScore
        );

        return [
            'score' => $averageScore,
            'verdict' => $verdict,
            'explanation' => $explanation,
        ];
    }
}