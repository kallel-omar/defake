<?php

declare(strict_types=1);

namespace App\Service;

final class VerdictDecisionService04B
{
    public function __construct(
        private readonly ScoreCalculator04B $scoreCalculator04B,
        private readonly EvidenceSourceMetrics04B $evidenceSourceMetrics04B,
    ) {
    }

    public function decide(
        array $scoreBreakdown,
        array $claimVerifiability,
        array $evidenceDecision,
        string $sourceDecision,
        string $riskDecision,
        array $officialSource
    ): array {
        if (($claimVerifiability['verifiable'] ?? false) !== true) {
            return [
                'score' => 0,
                'verdict' => 'NOT_VERIFIABLE',
                'capsApplied' => ['NO_CLEAR_CLAIM'],
            ];
        }

        $evidenceMatch = (int) ($scoreBreakdown['evidenceMatch']['score'] ?? 0);
        $sourceAuthority = (int) ($scoreBreakdown['sourceAuthority']['score'] ?? 0);
        $sourceIndependence = (int) ($scoreBreakdown['sourceIndependence']['score'] ?? 0);
        $riskSafety = (int) ($scoreBreakdown['riskSafety']['score'] ?? 0);
        $totalScore = (int) ($scoreBreakdown['total']['score'] ?? 0);

        $status = strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN'));
        $claimType = (string) ($claimVerifiability['claimType'] ?? 'unknown');

        $capsApplied = [];

        if ($status === 'CONTRADICTED') {
            return [
                'score' => min($totalScore, 25),
                'verdict' => 'Likely Fake',
                'capsApplied' => ['DIRECT_REFUTATION'],
            ];
        }

        $verdict = match (true) {
            $totalScore >= 80 => 'Likely Trusted',
            $totalScore >= 40 => 'Suspicious',
            default => 'Likely Fake',
        };

        if (
            $status === 'SUPPORTED'
            && $sourceAuthority === 0
            && $sourceIndependence === 0
            && $verdict === 'Likely Fake'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'NO_USABLE_EVIDENCE_SOURCE';
        }

        $isOfficialSelfAnnouncement =
            ($officialSource['official'] ?? false) === true
            && $sourceAuthority >= 23
            && $status === 'SUPPORTED';

        if (
            $verdict === 'Likely Trusted'
            && !$isOfficialSelfAnnouncement
            && ($evidenceMatch < 40 || $sourceAuthority < 15)
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY';
        }

        if (
            in_array($status, ['UNRELATED'], true)
            && $verdict === 'Likely Trusted'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'DIFFERENT_CONTEXT_ONLY';
        }

        if (
            $sourceAuthority <= 9
            && $sourceIndependence <= 6
            && !$isOfficialSelfAnnouncement
            && $verdict === 'Likely Trusted'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'WEAK_REPEATED_RUMORS';
        }

        $seriousClaimTypes = [
            'sports',
            'politics',
            'business',
            'legal',
            'health',
            'weather',
            'official_announcement',
        ];

        if (
            in_array($claimType, $seriousClaimTypes, true)
            && $sourceAuthority < 15
            && !$isOfficialSelfAnnouncement
            && $verdict === 'Likely Trusted'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'SERIOUS_CLAIM_NEEDS_STRONG_SOURCE';
        }

        if (
            in_array($claimType, $seriousClaimTypes, true)
            && !$isOfficialSelfAnnouncement
            && !in_array($sourceDecision, ['PRIMARY_OFFICIAL', 'PRIMARY_DOCUMENT', 'PRIMARY_DOCUMENT_OR_TOP_SOURCE'], true)
            && $sourceAuthority < 23
            && $verdict === 'Likely Trusted'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'SERIOUS_CLAIM_NEEDS_PRIMARY_OR_TOP_SOURCE';
        }

        if (
            $riskSafety <= 3
            && $evidenceMatch < 40
            && $sourceAuthority < 15
            && $verdict === 'Likely Trusted'
        ) {
            $verdict = 'Suspicious';
            $capsApplied[] = 'HIGH_RISK_WITH_WEAK_EVIDENCE';
        }

        if ($status === 'PARTIALLY_SUPPORTED' && $verdict === 'Likely Trusted') {
            $verdict = 'Suspicious';
            $capsApplied[] = 'PARTIAL_SUPPORT_ONLY';
        }

        if (in_array($status, ['UNSUPPORTED', 'UNKNOWN'], true) && $verdict === 'Likely Trusted') {
            $verdict = 'Suspicious';
            $capsApplied[] = 'NO_DIRECT_SUPPORT';
        }

        return [
            'score' => $totalScore,
            'verdict' => $verdict,
            'capsApplied' => array_values(array_unique($capsApplied)),
        ];
    }

    public function detectSourceDecision(
        array $officialSource,
        array $evidenceItems,
        array $relevantIndexes = []
    ): string {
        if (($officialSource['official'] ?? false) === true) {
            return 'PRIMARY_OFFICIAL';
        }

        $relevantItems = $this->evidenceSourceMetrics04B->selectRelevantItems($evidenceItems, $relevantIndexes);

        if (empty($relevantItems)) {
            return 'UNKNOWN';
        }

        $maxConfidence = 0;

        foreach ($relevantItems as $item) {
            $maxConfidence = max($maxConfidence, (int) ($item['confidenceScore'] ?? $item['sourceScore'] ?? 0));
        }

        return match (true) {
            $maxConfidence >= 90 => 'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            $maxConfidence >= 75 => 'REPUTABLE_MEDIA',
            $maxConfidence >= 60 => 'KNOWN_MEDIA',
            $maxConfidence >= 40 => 'WEAK_MEDIA',
            $maxConfidence >= 20 => 'SOCIAL_OR_LOW_AUTHORITY_SOURCE',
            default => 'UNKNOWN',
        };
    }

    public function detectRiskDecision(string $postText): string
    {
        $riskSafety = $this->scoreCalculator04B->calculateRiskSafetyScore($postText);

        return match (true) {
            $riskSafety >= 9 => 'LOW_RISK',
            $riskSafety >= 7 => 'MINOR_RISK',
            $riskSafety >= 4 => 'MEDIUM_RISK',
            $riskSafety >= 1 => 'HIGH_RISK',
            default => 'SEVERE_RISK',
        };
    }
}