<?php

namespace App\Service;

use App\Exception\AnalysisConfigurationException;
use App\Exception\AnalysisPermanentException;
use App\Exception\AnalysisTransientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PostAnalysisService
{
    public function __construct(
    private readonly string $serperApiKey,
    private readonly HttpClientInterface $httpClient,
     private readonly InternetEvidenceSearchService $internetEvidenceSearchService,
    private readonly EvidenceFormatterService $evidenceFormatterService,
    private readonly ScoreBreakdownBuilder $scoreBreakdownBuilder,
    private readonly ScoreCalculator04B $scoreCalculator04B,
    private readonly EvidenceSourceMetrics04B $evidenceSourceMetrics04B,
    private readonly OfficialSourceDetectorService $officialSourceDetectorService,
    private readonly EvidenceDecisionService $evidenceDecisionService,
    private readonly ClaimExtractionService $claimExtractionService,
    private readonly ClaimVerifiabilityService $claimVerifiabilityService,
    
) {
}

    public function analyze(string $url, string $postText, array $sourceContext = []): array
{
    $originalPostText = $postText;

    $claims = $this->claimExtractionService->extract($postText);
    $mainClaim = trim((string) ($claims[0] ?? ''));

    $claimVerifiability = $this->claimVerifiabilityService->assess($mainClaim, $originalPostText);

    if (($claimVerifiability['verifiable'] ?? false) !== true) {
        return [
            // Keep 0 for now because the database/UI may still expect an integer score.
            // The verdict tells the UI this is NOT_VERIFIABLE, not Likely Fake.
            'score' => 0,
            'verdict' => 'NOT_VERIFIABLE',
            'mainClaim' => null,
            'evidenceSources' => [],

            // New 04B scoring system
            'scoringVersion' => '04B',
            'scoreBreakdown' => null,
            'claimVerifiability' => $claimVerifiability,
            'evidenceDecision' => 'NO_CLEAR_CLAIM',
            'sourceDecision' => 'NOT_ANALYZED',
            'riskDecision' => 'NOT_ANALYZED',
            'capsApplied' => ['NO_CLEAR_CLAIM'],

            // Old fields kept temporarily so Twig does not break
            'evidenceScore' => 0,
            'sourceScore' => 0,
            'languageScore' => 0,
            'verificationScore' => 0,

            'evidenceReason' => 'No evidence search was performed because no clear factual claim was detected.',
            'sourceReason' => 'Source analysis was skipped because the post is not a factual news claim.',
            'languageReason' => 'The post is not clear enough to be safely checked as a factual news claim.',
            'verificationReason' => $claimVerifiability['reason'] ?? 'Verification was skipped because there is no specific factual claim to check.',
            'explanation' => 'This post does not contain a clear verifiable factual claim. DeFake cannot safely check vague commentary, opinion, sarcasm, insults, emotional reactions, or future rumors without concrete details.',
        ];
    }

    $searchQuery = $this->limitText($originalPostText, 1000) . "\n\nClaim to verify:\n" . $mainClaim;

  

$internetEvidenceData = $this->internetEvidenceSearchService->search($searchQuery, $mainClaim);


        $evidenceItems = $internetEvidenceData['items'];

        $postText = $this->limitText($postText, 1500);

        $evidenceDecision = $this->evidenceDecisionService->decide(
            $mainClaim,
            $evidenceItems
        );

        $officialSource = $this->officialSourceDetectorService->detect($sourceContext, $postText);

      $formattedEvidenceSources = $this->evidenceFormatterService->formatSources(
    $evidenceItems,
    $mainClaim,
    $evidenceDecision['relevantIndexes'] ?? []
);

$verificationContextSafe = $this->isVerificationContextSafe04B(
    $evidenceDecision,
    $formattedEvidenceSources,
    $officialSource
);



$scoreBreakdown04B = $this->scoreBreakdownBuilder->build(
    $this->scoreCalculator04B->calculateEvidenceMatchScore(
    $evidenceDecision,
    $verificationContextSafe,
    $formattedEvidenceSources,
    $officialSource
),
    $this->scoreCalculator04B->calculateSourceAuthorityScore($officialSource, $formattedEvidenceSources),
    $this->scoreCalculator04B->calculateSourceIndependenceScore($officialSource, $formattedEvidenceSources),
    $this->scoreCalculator04B->calculateRiskSafetyScore($originalPostText),
    [
        'evidenceMatch' => $this->explainEvidenceMatch04B(
    $evidenceDecision,
    $verificationContextSafe,
    $formattedEvidenceSources,
    $officialSource
),
        'sourceAuthority' => $this->explainSourceAuthority04B($officialSource, $formattedEvidenceSources),
        'sourceIndependence' => $this->explainSourceIndependence04B($officialSource, $formattedEvidenceSources),
        'riskSafety' => 'Risk safety is estimated from the original post wording.',
    ]
);
$verdict04B = $this->decideVerdict04B(
    $scoreBreakdown04B,
    $claimVerifiability,
    $evidenceDecision,
    $this->detectSourceDecision04B($officialSource, $formattedEvidenceSources),
    $this->detectRiskDecision04B($originalPostText),
    $officialSource
);

        return [
           'score' => $verdict04B['score'],
'verdict' => $verdict04B['verdict'],
            'mainClaim' => $mainClaim,
            'scoringVersion' => '04B',
            'score04B' => $verdict04B['score'],
'verdict04B' => $verdict04B['verdict'],
'explanation04B' => $this->explainVerdict04B($verdict04B),
'scoreBreakdown' => $scoreBreakdown04B,
'claimVerifiability' => $claimVerifiability,
'evidenceDecision' => $evidenceDecision['status'] ?? 'UNKNOWN',
'sourceDecision' => $this->detectSourceDecision04B($officialSource, $formattedEvidenceSources),
'riskDecision' => $this->detectRiskDecision04B($originalPostText),
'capsApplied' => $verdict04B['capsApplied'],
            'evidenceSources' => $formattedEvidenceSources,

            'evidenceScore' => (int) ($scoreBreakdown04B['evidenceMatch']['score'] ?? 0),
'sourceScore' => (int) ($scoreBreakdown04B['sourceAuthority']['score'] ?? 0),
'languageScore' => (int) ($scoreBreakdown04B['sourceIndependence']['score'] ?? 0),
'verificationScore' => (int) ($scoreBreakdown04B['riskSafety']['score'] ?? 0),

'evidenceReason' => $scoreBreakdown04B['evidenceMatch']['reason'] ?? '',
'sourceReason' => $scoreBreakdown04B['sourceAuthority']['reason'] ?? '',
'languageReason' => $scoreBreakdown04B['sourceIndependence']['reason'] ?? '',
'verificationReason' => $scoreBreakdown04B['riskSafety']['reason'] ?? '',
            'explanation' => $this->explainVerdict04B($verdict04B),
        ];
    }

    private function limitText(?string $text, int $maxChars): string
    {
        $text = trim((string) $text);

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n...[truncated]";
    }

private function hasStrongEvidenceSource(array $evidenceItems, array $relevantIndexes = []): bool
{
    $itemsToCheck = [];

    if ($relevantIndexes !== []) {
        foreach ($relevantIndexes as $index) {
            if (isset($evidenceItems[$index])) {
                $itemsToCheck[] = $evidenceItems[$index];
            }
        }
    } else {
        $itemsToCheck = $evidenceItems;
    }

    foreach ($itemsToCheck as $item) {
        $confidenceScore = (int) ($item['confidenceScore'] ?? 0);
        $officialConfidence = (int) ($item['officialConfidence'] ?? 0);
        $officialCategory = (string) ($item['officialCategory'] ?? 'unknown');

        $isPrimaryOfficialSource =
            $officialConfidence >= 65 &&
            !in_array($officialCategory, [
                'media',
                'fan_page',
                'journalist',
                'commentary',
                'unknown',
            ], true);

        if ($isPrimaryOfficialSource || $confidenceScore >= 70) {
            return true;
        }
    }

    return false;
}
private function calculateExternalEvidenceScore(array $evidenceItems, array $relevantIndexes = []): int
{
    $itemsToCheck = $this->getRelevantEvidenceItems($evidenceItems, $relevantIndexes);

    if ($itemsToCheck === []) {
    return 0;
}
    $officialSources = [];
    $credibleSources = [];
    $weakSources = [];

    foreach ($itemsToCheck as $item) {
        $sourceKey = $this->getEvidenceSourceKey($item);

        if ($sourceKey === '') {
            continue;
        }

        if ($this->isOfficialPrimaryEvidenceSource($item)) {
            $officialSources[$sourceKey] = true;
            continue;
        }

        if ($this->isCredibleIndependentEvidenceSource($item)) {
            $credibleSources[$sourceKey] = true;
            continue;
        }

        $weakSources[$sourceKey] = true;
    }
if (count($officialSources) >= 1) {
    return 25;
}

if (count($credibleSources) >= 2) {
    return 22;
}

if (count($credibleSources) === 1) {
    return 20;
}

if (count($weakSources) >= 2) {
    return 15;
}

if (count($weakSources) === 1) {
    return 10;
}

return 0;
}

private function getRelevantEvidenceItems(array $evidenceItems, array $relevantIndexes = []): array
{
    if ($relevantIndexes === []) {
        return $evidenceItems;
    }

    $items = [];

    foreach ($relevantIndexes as $index) {
        if (isset($evidenceItems[$index])) {
            $items[] = $evidenceItems[$index];
        }
    }

    return $items;
}

private function isOfficialPrimaryEvidenceSource(array $item): bool
{
    $officialConfidence = (int) ($item['officialConfidence'] ?? 0);
    $officialCategory = (string) ($item['officialCategory'] ?? 'unknown');

    return $officialConfidence >= 65
        && !in_array($officialCategory, [
            'media',
            'fan_page',
            'journalist',
            'commentary',
            'unknown',
        ], true);
}

private function isCredibleIndependentEvidenceSource(array $item): bool
{
    $confidenceScore = (int) ($item['confidenceScore'] ?? 0);
    $officialCategory = (string) ($item['officialCategory'] ?? 'unknown');

    if (in_array($officialCategory, [
        'fan_page',
        'commentary',
        'unknown',
    ], true)) {
        return false;
    }

    return $confidenceScore >= 70;
}

private function getEvidenceSourceKey(array $item): string
{
    $link = trim((string) ($item['link'] ?? ''));

    if ($link !== '') {
        $host = parse_url($link, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return preg_replace('/^www\./', '', mb_strtolower($host)) ?? mb_strtolower($host);
        }
    }

    return mb_strtolower(trim((string) ($item['source'] ?? '')));
}


private function explainExternalEvidenceScore(int $score): string
{
    return match ($score) {
        25 => 'An official or primary source confirms the same claim.',
        22 => 'Multiple credible independent sources confirm the same claim.',
        20 => 'One credible independent source confirms the same claim.',
        15 => 'The same claim appears in multiple weak or non-official sources, but no credible independent or official source confirms it yet.',
        10 => 'The same claim appears in one weak or non-official source, but this is not enough for full confirmation.',
        0 => 'No supporting external evidence was found for the same claim.',
        default => 'External evidence was found, but its strength is limited or unclear.',
    };
}
    private function failedResult(string $explanation): array
    {
        return [
            'score' => 0,
            'verdict' => 'Analysis Failed',
            'evidenceSources' => [],
            'evidenceScore' => 0,
            'sourceScore' => 0,
            'languageScore' => 0,
            'verificationScore' => 0,
            'evidenceReason' => '',
            'sourceReason' => '',
            'languageReason' => '',
            'verificationReason' => '',
            'explanation' => 'This post does not contain a clear verifiable factual claim. DeFake cannot safely check vague commentary, opinion, sarcasm, insults, emotional reactions, or future rumors without concrete details.',
            'mainClaim' => null,
        ];
    }
    
   
private function explainSourceAuthority04B(array $officialSource, array $formattedEvidenceSources): string
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
private function explainVerdict04B(array $verdict04B): string
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
private function decideVerdict04B(
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
    // If the AI/evidence decision says SUPPORTED but DeFake has no usable
// evidence source to show, do not call it fake. Treat it as unresolved.
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

    // Strong trust rule:
    // Likely Trusted requires strong evidence match + strong source,
    // unless it is an official/primary self-announcement.
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

// 04B safety cap:
// Serious/public claims should not become Likely Trusted from normal media alone.
// They need an official/primary source or a very top source.
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

private function explainSourceIndependence04B(array $officialSource, array $formattedEvidenceSources): string
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
private function isVerificationContextSafe04B(
    array $evidenceDecision,
    array $formattedEvidenceSources,
    array $officialSource
): bool {
    $status = strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN'));

    if ($status !== 'SUPPORTED') {
        return false;
    }

    $isOfficialSource = ($officialSource['official'] ?? false) === true;
    $supportCount = (int) ($evidenceDecision['supportCount'] ?? 0);
    $relevantIndexes = $evidenceDecision['relevantIndexes'] ?? [];

    if (!is_array($relevantIndexes)) {
        $relevantIndexes = [];
    }

    // If the original post is from an official source and the evidence relation is supported,
    // we allow high context safety. Source authority is still scored separately.
    if ($isOfficialSource && $supportCount >= 1) {
        return true;
    }

    // For non-official sources, require at least two relevant evidence hits
    // and at least two usable/displayable evidence sources.
    if ($supportCount < 2 || count($relevantIndexes) < 2) {
        return false;
    }

    if (count($formattedEvidenceSources) < 2) {
        return false;
    }

    return true;
}




private function explainEvidenceMatch04B(
    array $evidenceDecision,
    bool $verificationContextSafe,
    array $formattedEvidenceSources = [],
    array $officialSource = []
): string
{
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



private function detectSourceDecision04B(
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

private function detectRiskDecision04B(string $postText): string
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
