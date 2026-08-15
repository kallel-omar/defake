<?php

namespace App\Service;


class PostAnalysisService
{
    public function __construct(
    private readonly InternetEvidenceSearchService $internetEvidenceSearchService,
    private readonly EvidenceFormatterService $evidenceFormatterService,
    private readonly ScoreBreakdownBuilder $scoreBreakdownBuilder,
    private readonly ScoreCalculator04B $scoreCalculator04B,
    private readonly VerdictDecisionService04B $verdictDecisionService04B,
    private readonly AnalysisExplanationService04B $analysisExplanationService04B,
    private readonly OfficialSourceDetectorService $officialSourceDetectorService,
    private readonly EvidenceDecisionService $evidenceDecisionService,
    private readonly ClaimExtractionService $claimExtractionService,
    private readonly ClaimVerifiabilityService $claimVerifiabilityService,
    private readonly NonVerifiableReasonService $nonVerifiableReasonService,
    
) {
}

    public function analyze(string $url, string $postText, array $sourceContext = [], array $analysisContext = []): array
{
    $originalPostText = $postText;

    $claims = $this->claimExtractionService->extract($postText, $analysisContext);
    $mainClaim = trim((string) ($claims[0] ?? ''));

    $claimVerifiability = $this->claimVerifiabilityService->assess($mainClaim, $originalPostText);
    

    if (($claimVerifiability['verifiable'] ?? false) !== true) {
        $nonVerifiableReason = $this->nonVerifiableReasonService->classify(
            $originalPostText,
            $mainClaim,
            $claimVerifiability
        );

        return [
            // Keep 0 for now because the database/UI may still expect an integer score.
            // The verdict tells the UI this is NOT_VERIFIABLE, not Likely Fake.
            'score' => 0,
            'verdict' => 'NOT_VERIFIABLE',
            'mainClaim' => null,
'evidenceSources' => [],
'lowConfidenceEvidenceCandidates' => [],

            // New 04B scoring system
            'scoringVersion' => '04B',
            'scoreBreakdown' => null,
            'claimVerifiability' => $claimVerifiability,
            'evidenceDecision' => 'NO_CLEAR_CLAIM',
            'sourceDecision' => 'NOT_ANALYZED',
            'riskDecision' => 'NOT_ANALYZED',
            'capsApplied' => ['NO_CLEAR_CLAIM'],
            'nonVerifiableReasonCode' => $nonVerifiableReason['code'],
            'contentTitle' => $nonVerifiableReason['title'],
            'contentSummary' => $nonVerifiableReason['summary'],

            // Old fields kept temporarily so Twig does not break
            'evidenceScore' => 0,
            'sourceScore' => 0,
            'languageScore' => 0,
            'verificationScore' => 0,

            'evidenceReason' => 'No evidence search was performed because no clear factual claim was detected.',
            'sourceReason' => 'Source analysis was skipped because the post is not a factual news claim.',
            'languageReason' => 'The post is not clear enough to be safely checked as a factual news claim.',
            'verificationReason' => $nonVerifiableReason['verificationReason'],
            'explanation' => $nonVerifiableReason['explanation'],
        ];
    }

    $searchQuery = $this->buildSearchQuery($originalPostText, $mainClaim, $analysisContext);

  

$internetEvidenceData = $this->internetEvidenceSearchService->search($searchQuery, $mainClaim);


        $evidenceItems = $internetEvidenceData['items'];

        $postText = $this->limitText($postText, 1500);

        $evidenceDecision = $this->evidenceDecisionService->decide(
            $mainClaim,
            $evidenceItems
        );

        $officialSource = $this->officialSourceDetectorService->detect(
    $sourceContext,
    $postText,
    $mainClaim
);
        $isOfficialSelfAnnouncement =
    ($officialSource['official'] ?? false) === true
    && ($officialSource['selfAnnouncement'] ?? false) === true;

$formattedEvidenceResult = $this->evidenceFormatterService->formatSourcesWithDebug(
    $evidenceItems,
    $mainClaim,
    $evidenceDecision['relevantIndexes'] ?? []
);

$formattedEvidenceSources = $formattedEvidenceResult['sources'];
$evidenceFormatterDebug = $formattedEvidenceResult['debug'];

$lowConfidenceEvidenceCandidates = $this->buildLowConfidenceEvidenceCandidates(
    $evidenceFormatterDebug['rejectedSources'] ?? []
);

$noDisplayablePositiveEvidence = false;
$rawEvidenceStatus = strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN'));

// A confirmed official source making a claim about something it directly
// controls is itself valid first-party evidence.
//
// External sources are still useful for independent corroboration, but
// their absence must not erase a genuine first-party announcement.
//
// Never override an explicit contradiction here.
if (
    $isOfficialSelfAnnouncement
    && $rawEvidenceStatus !== 'CONTRADICTED'
) {
    $evidenceDecision['status'] = 'SUPPORTED';
    $evidenceDecision['supportCount'] = max(
        1,
        (int) ($evidenceDecision['supportCount'] ?? 0)
    );

    $evidenceDecision['reason'] =
        'The original post is a confirmed official first-party announcement about a matter controlled by the publishing organization.';
} elseif (
    $formattedEvidenceSources === []
    && in_array($rawEvidenceStatus, ['SUPPORTED', 'PARTIALLY_SUPPORTED'], true)
) {
    $noDisplayablePositiveEvidence = true;

    $evidenceDecision['status'] = 'UNSUPPORTED';
    $evidenceDecision['supportCount'] = 0;
    $evidenceDecision['relevantIndexes'] = [];
    $evidenceDecision['reason'] = sprintf(
        'Evidence search returned %s, but no usable/displayable evidence source was available after source filtering.',
        $rawEvidenceStatus
    );
}
$noDisplayableNonContradictoryEvidence =
    !$isOfficialSelfAnnouncement
    && $formattedEvidenceSources === []
    && strtoupper((string) ($evidenceDecision['status'] ?? 'UNKNOWN')) !== 'CONTRADICTED';

if ($noDisplayableNonContradictoryEvidence && !$noDisplayablePositiveEvidence) {
    $evidenceDecision['reason'] = 'DeFake did not find any usable/displayable evidence source for this claim.';
}
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
    'evidenceMatch' => $this->analysisExplanationService04B->explainEvidenceMatch(
        $evidenceDecision,
        $verificationContextSafe,
        $formattedEvidenceSources,
        $officialSource
    ),
    'sourceAuthority' => $this->analysisExplanationService04B->explainSourceAuthority($officialSource, $formattedEvidenceSources),
    'sourceIndependence' => $this->analysisExplanationService04B->explainSourceIndependence($officialSource, $formattedEvidenceSources),
    'riskSafety' => 'Risk safety is estimated from the original post wording.',
]
);
if ($noDisplayableNonContradictoryEvidence) {
    $scoreBreakdown04B['evidenceMatch']['reason'] = (string) ($evidenceDecision['reason'] ?? '');
}

$sourceDecision04B = $this->verdictDecisionService04B->detectSourceDecision(
    $officialSource,
    $formattedEvidenceSources
);

$riskDecision04B = $this->verdictDecisionService04B->detectRiskDecision($originalPostText);

$verdict04B = $this->verdictDecisionService04B->decide(
    $scoreBreakdown04B,
    $claimVerifiability,
    $evidenceDecision,
    $sourceDecision04B,
    $riskDecision04B,
    $officialSource
);

if ($noDisplayableNonContradictoryEvidence && ($verdict04B['verdict'] ?? '') === 'Likely Fake') {
    $verdict04B['verdict'] = 'Suspicious';
    $verdict04B['score'] = max(31, (int) ($verdict04B['score'] ?? 0));

    $capsApplied = $verdict04B['capsApplied'] ?? [];

    if (!is_array($capsApplied)) {
        $capsApplied = [];
    }

    $capsApplied[] = 'NO_USABLE_DISPLAYABLE_EVIDENCE';
    $verdict04B['capsApplied'] = array_values(array_unique($capsApplied));
}

$explanation04B = $this->analysisExplanationService04B->explainVerdict($verdict04B);

if ($noDisplayableNonContradictoryEvidence) {
    $explanation04B = 'DeFake did not find any usable/displayable evidence source that can support or refute this claim. The claim remains Suspicious until a clear displayable source is available.';
}
 $evidenceDebug = null;

if (($analysisContext['debugEvidence'] ?? false) === true) {
 $evidenceDebug = [
    'search' => $internetEvidenceData['debug'] ?? null,
    'rankedEvidenceItems' => $evidenceItems,
    'evidenceDecision' => $evidenceDecision,
    'formattedEvidenceSources' => $formattedEvidenceSources,
    'formatter' => $evidenceFormatterDebug,
];
}
        return [
           'score' => $verdict04B['score'],
'verdict' => $verdict04B['verdict'],
            'mainClaim' => $mainClaim,
            'scoringVersion' => '04B',
            'score04B' => $verdict04B['score'],
'verdict04B' => $verdict04B['verdict'],
'explanation04B' => $explanation04B,
'scoreBreakdown' => $scoreBreakdown04B,
'claimVerifiability' => $claimVerifiability,
'evidenceDecision' => $evidenceDecision['status'] ?? 'UNKNOWN',
'sourceDecision' => $sourceDecision04B,
'riskDecision' => $riskDecision04B,
'capsApplied' => $verdict04B['capsApplied'],
            'evidenceSources' => $formattedEvidenceSources,
            'lowConfidenceEvidenceCandidates' => $lowConfidenceEvidenceCandidates,
            'evidenceDebug' => $evidenceDebug,

            'evidenceScore' => (int) ($scoreBreakdown04B['evidenceMatch']['score'] ?? 0),
'sourceScore' => (int) ($scoreBreakdown04B['sourceAuthority']['score'] ?? 0),
'languageScore' => (int) ($scoreBreakdown04B['sourceIndependence']['score'] ?? 0),
'verificationScore' => (int) ($scoreBreakdown04B['riskSafety']['score'] ?? 0),

'evidenceReason' => $scoreBreakdown04B['evidenceMatch']['reason'] ?? '',
'sourceReason' => $scoreBreakdown04B['sourceAuthority']['reason'] ?? '',
'languageReason' => $scoreBreakdown04B['sourceIndependence']['reason'] ?? '',
'verificationReason' => $scoreBreakdown04B['riskSafety']['reason'] ?? '',
            'explanation' => $explanation04B,
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

    private function buildSearchQuery(string $originalPostText, string $mainClaim, array $analysisContext = []): string
    {
        $searchQuery = $this->limitText($originalPostText, 1000) . "\n\nClaim to verify:\n" . $mainClaim;

        $contextLines = [];
        $country = strtoupper(trim((string) ($analysisContext['country'] ?? '')));
        $topic = trim((string) ($analysisContext['topic'] ?? ''));

        if ($country !== '' && $country !== 'GLOBAL') {
            $contextLines[] = 'Context country: ' . $country;
        }

        if ($topic !== '') {
            $contextLines[] = 'Context topic: ' . $topic;
        }

        if ($contextLines !== []) {
            $searchQuery .= "\n\nSearch context hints:\n" . implode("\n", $contextLines);
        }

        return $searchQuery;
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
private function buildLowConfidenceEvidenceCandidates(array $rejectedSources): array
{
    $candidates = [];

    foreach ($rejectedSources as $source) {
        if (($source['reason'] ?? '') !== 'source_confidence_below_display_threshold') {
            continue;
        }

        $link = trim((string) ($source['link'] ?? ''));

        if ($link === '') {
            continue;
        }

        $candidates[] = [
            'title' => (string) ($source['title'] ?? 'No title'),
            'link' => $link,
            'source' => $source['source'] ?? null,
            'rejectionReason' => 'source_confidence_below_display_threshold',
            'confidenceScore' => (int) ($source['confidenceScore'] ?? 0),
            'requiredConfidenceScore' => (int) ($source['requiredConfidenceScore'] ?? 60),
            'confidenceLabel' => (string) ($source['confidenceLabel'] ?? 'Unknown'),
            'confidenceType' => (string) ($source['confidenceType'] ?? 'unknown'),
        ];
    }

    return $candidates;
}

}
