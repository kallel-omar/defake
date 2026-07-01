<?php

declare(strict_types=1);

namespace App\Service;

final class AnalysisExplanationService04B
{
    public function __construct(
        private readonly EvidenceSourceMetrics04B $evidenceSourceMetrics04B,
    ) {
    }

    public function explainSourceAuthority(array $officialSource, array $formattedEvidenceSources): string
    {
        if (($officialSource['official'] ?? false) === true) {
            return 'The original Facebook source appears to be official for this type of claim.';
        }

        if (empty($formattedEvidenceSources)) {
            return 'No relevant evidence source was available to assess source authority.';
        }

        $best = null;
        $bestScore = -1;

        foreach ($formattedEvidenceSources as $source) {
            $score = (int) ($source['confidenceScore'] ?? 0);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $source;
            }
        }

        $sourceName = (string) ($best['source'] ?? 'unknown source');
        $label = (string) ($best['confidenceLabel'] ?? 'unknown authority');

        return sprintf(
            'The strongest relevant evidence source is %s with confidence %d/100 (%s).',
            $sourceName,
            max(0, $bestScore),
            $label
        );
    }

    public function explainVerdict(array $verdict04B): string
    {
        $verdict = (string) ($verdict04B['verdict'] ?? 'Suspicious');
        $capsApplied = $verdict04B['capsApplied'] ?? [];

        if ($verdict === 'NOT_VERIFIABLE') {
            return 'DeFake could not safely verify this post because it does not contain a clear factual claim.';
        }

        if (in_array('SERIOUS_CLAIM_NEEDS_PRIMARY_OR_TOP_SOURCE', $capsApplied, true)) {
            return 'The evidence appears to match the claim and comes from reputable sources, but this is a serious or official-type claim. DeFake needs an official, primary, or top-tier source before marking it as Likely Trusted.';
        }

        if (in_array('WEAK_REPEATED_RUMORS', $capsApplied, true)) {
            return 'The claim appears in weak or repeated sources, but repeated rumors are not enough for full trust.';
        }

        if (in_array('DIFFERENT_CONTEXT_ONLY', $capsApplied, true)) {
            return 'The evidence is related, but it appears to describe a different context, date, event, or situation.';
        }

        if (in_array('DIRECT_REFUTATION', $capsApplied, true)) {
            return 'Strong evidence appears to directly refute the claim.';
        }

        if (in_array('PARTIAL_SUPPORT_ONLY', $capsApplied, true)) {
            return 'The evidence supports part of the claim, but not enough to fully confirm it.';
        }

        if ($verdict === 'Likely Trusted') {
            return 'The claim has strong matching evidence, strong enough source authority, and no major safety cap was applied.';
        }

        if ($verdict === 'Likely Fake') {
            return 'The claim has little or no matching support, or strong evidence appears to contradict it.';
        }

        if (in_array('NO_USABLE_EVIDENCE_SOURCE', $capsApplied, true)) {
            return 'The claim is specific enough to check, but DeFake did not find a usable source to confirm or refute it. Because there is no direct contradiction, the result remains Suspicious rather than Likely Fake.';
        }

        return 'The claim has some supporting evidence, but DeFake did not find enough primary or authoritative confirmation to mark it as Likely Trusted.';
    }

    public function explainSourceIndependence(array $officialSource, array $formattedEvidenceSources): string
    {
        $hosts = [];

        foreach ($formattedEvidenceSources as $source) {
            $host = $this->evidenceSourceMetrics04B->extractHost($source);

            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        $distinctSources = count($hosts);

        if (($officialSource['official'] ?? false) === true) {
            return sprintf(
                'The original source is official; %d additional distinct evidence source(s) were found.',
                $distinctSources
            );
        }

        return sprintf(
            'DeFake found %d distinct relevant evidence source(s). Repeated sources count less than independent sources.',
            $distinctSources
        );
    }

    public function explainEvidenceMatch(
        array $evidenceDecision,
        bool $verificationContextSafe,
        array $formattedEvidenceSources = [],
        array $officialSource = []
    ): string {
        $status = strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN'));
        $reason = trim((string) ($evidenceDecision['reason'] ?? ''));

        $hasUsableEvidenceSource = !empty($formattedEvidenceSources);
        $isOfficialSource = ($officialSource['official'] ?? false) === true;

        if (!$hasUsableEvidenceSource && $isOfficialSource) {
            return match ($status) {
                'SUPPORTED' => 'The original source appears to be official and the post concerns its own activity, so DeFake treats it as primary evidence for the claim.',
                'PARTIALLY_SUPPORTED' => 'The original source appears official, but the claim is only partially supported by the available context.',
                'CONTRADICTED' => 'The original source appears official, but other evidence may contradict the claim.',
                default => 'The original source appears official, but DeFake could not determine a clear evidence relationship.',
            };
        }

        if (!$hasUsableEvidenceSource && !$isOfficialSource) {
            return match ($status) {
                'SUPPORTED' => 'The evidence relation was marked as supported, but DeFake could not keep any usable source to display. This is treated as unresolved support, not full confirmation.',
                'PARTIALLY_SUPPORTED' => 'The claim may have partial support, but DeFake could not keep any usable source to display.',
                'CONTRADICTED' => 'The claim may be contradicted, but DeFake could not keep any usable source to display.',
                default => 'DeFake did not find a usable evidence source for this claim.',
            };
        }

        return match ($status) {
            'SUPPORTED' => $verificationContextSafe
                ? 'The available evidence appears to match the same real-world claim and context.'
                : 'The evidence is related, but DeFake could not safely confirm that it matches the exact same context.',

            'PARTIALLY_SUPPORTED' => $reason !== ''
                ? $reason
                : 'The evidence supports part of the claim, but does not fully confirm all core details.',

            'UNRELATED' => $reason !== ''
                ? $reason
                : 'The evidence mentions a related topic or similar entities, but not the same real-world situation.',

            'UNSUPPORTED' => $reason !== ''
                ? $reason
                : 'No relevant evidence was found confirming the specific claim.',

            'CONTRADICTED' => $reason !== ''
                ? $reason
                : 'The available evidence appears to contradict the claim.',

            default => 'DeFake could not determine a clear evidence relationship for this claim.',
        };
    }
}