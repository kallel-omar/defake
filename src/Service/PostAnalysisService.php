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
) {
}

    public function analyze(string $url, string $postText, array $sourceContext = [], array $analysisContext = []): array
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

    $searchQuery = $this->buildSearchQuery($originalPostText, $mainClaim, $analysisContext);

  

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
$explanation04B = $this->analysisExplanationService04B->explainVerdict($verdict04B);
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

}
