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
    private readonly VerdictDecisionService04B $verdictDecisionService04B,
    private readonly AnalysisExplanationService04B $analysisExplanationService04B,
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
