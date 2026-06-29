<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PostAnalysisService
{
    public function __construct(
    private readonly string $serperApiKey,
    private readonly HttpClientInterface $httpClient,
     
    private readonly SourceConfidenceService $sourceConfidenceService,
    private readonly OfficialSourceDetectorService $officialSourceDetectorService,
    private readonly EvidenceDecisionService $evidenceDecisionService,
    private readonly ClaimExtractionService $claimExtractionService,
    private readonly ClaimVerifiabilityService $claimVerifiabilityService,
    
) {
}

    public function analyze(string $url, string $postText, array $sourceContext = []): array
{
    $originalPostText = $postText;

    $claims = $this->claimExtractionService->extract($postText, $sourceContext);
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

  

$internetEvidenceData = $this->searchInternetEvidence($searchQuery, $mainClaim);


        $internetEvidence = $internetEvidenceData['text'];
        $evidenceItems = $internetEvidenceData['items'];

        $postText = $this->limitText($postText, 1500);
        $internetEvidence = $this->limitText($internetEvidence, 2500);

        $evidenceDecision = $this->evidenceDecisionService->decide(
            $mainClaim,
            $evidenceItems
        );

        $officialSource = $this->officialSourceDetectorService->detect($sourceContext, $postText);

        $pageName = $sourceContext['pageName'] ?? 'Unknown';
        $userName = $sourceContext['userName'] ?? 'Unknown';
        $userId = $sourceContext['userId'] ?? 'Unknown';
        $postUrl = $sourceContext['postUrl'] ?? 'Unknown';
        $officialText = $officialSource['official'] ? 'yes' : 'no';
        $officialReason = $officialSource['reason'];

        $prompt = <<<PROMPT
You are DeFake, an AI fact-checking assistant.

Analyze the credibility of the following Facebook post using:
1. The Facebook post text
2. The internet search evidence
3. The Facebook source context

Always return the explanation and reasons in English.

URL:
$url

Post Text:
$postText

Internet Evidence:
$internetEvidence

Facebook Source Context:
Page name: $pageName
User name: $userName
User ID: $userId
Post URL: $postUrl

Official Source Detection:
Official source: $officialText
Reason: $officialReason

Important:
If the Facebook page is an official organization page and the announcement concerns that organization itself, treat the Facebook page as a primary source.

If the Facebook source is not official, do NOT automatically treat the post as fake.
A non-official page may still publish a true claim.
For non-official pages, base the verdict mainly on whether credible internet evidence supports or contradicts the claim.
If the internet evidence contains credible sources confirming the same factual claim, treat the claim as supported.

Do not say the internet evidence contradicts the claim unless at least one credible source explicitly says the claim is false or gives different facts.

A source is relevant only if it confirms the same event, action, entities, date, number, location, or decision mentioned in the claim.

Do not treat loosely related sources as support.
Same topic, same organization, or same country is not enough.

Only mark a post as Likely Fake when credible evidence contradicts the claim, or when a serious factual claim has no reliable support at all.

Evaluation criteria:
- Does the post provide evidence?
- If the source is not official, is the claim still supported by credible external evidence?
- Do internet results support or contradict the post?
- Is the Facebook source an official organization page?
- Is there missing context?
- Is the language manipulative or emotional?
- Are there extraordinary claims without proof?

Score the following categories from 0 to 25:

Evidence Score:
25 = direct evidence or primary source
15 = partial evidence or multiple supporting sources
5 = weak evidence
0 = no evidence

Language Score:
25 = neutral, factual, clear language
20 = mostly neutral and informative
15 = somewhat emotional or persuasive
5 = highly emotional, manipulative, biased, insulting, or conspiratorial
0 = extreme manipulation, propaganda, hate, or inflammatory language

Important:
The languageScore must match the languageReason.
If the languageReason says the post is neutral or factual, never give languageScore 0.
If the post is neutral and factual, languageScore should be between 20 and 25.

Return ONLY valid JSON:

{
  "evidenceScore": 0,
  "languageScore": 0,
  "evidenceReason": "",
  "sourceReason": "",
  "languageReason": "",
  "verificationReason": "",
  "explanation": ""
}
PROMPT;

      $formattedEvidenceSources = $this->formatEvidenceSources(
    $evidenceItems,
    $mainClaim,
    $evidenceDecision['relevantIndexes'] ?? []
);

$verificationContextSafe = $this->isVerificationContextSafe04B(
    $evidenceDecision,
    $formattedEvidenceSources,
    $officialSource
);



$scoreBreakdown04B = $this->buildScoreBreakdown(
    $this->calculateEvidenceMatchScore04B(
    $evidenceDecision,
    $verificationContextSafe,
    $formattedEvidenceSources,
    $officialSource
),
    $this->calculateSourceAuthorityScore04B($officialSource, $formattedEvidenceSources),
    $this->calculateSourceIndependenceScore04B($officialSource, $formattedEvidenceSources),
    $this->calculateRiskSafetyScore04B($originalPostText),
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

    private function searchInternetEvidence(string $postText, ?string $claim = null): array
{
    $query = $postText;
    $data = $this->callSerper('news', $query);
    $items = $data['news'] ?? [];

    if (empty($items)) {
        $data = $this->callSerper('search', $query);
        $items = $data['organic'] ?? [];
    }

    if (empty($items)) {
        return [
            'text' => 'No internet evidence found.',
            'items' => [],
        ];
    }

    $results = [];

    $rankedItems = [];

foreach (array_slice($items, 0, 10) as $item) {
    $relevanceScore = $this->scoreEvidenceRelevance($item, $claim ?? $postText);

    if ($relevanceScore < 3) {
        continue;
    }

    $link = $item['link'] ?? '';

    if ($link === '') {
        continue;
    }

    $confidence = $this->sourceConfidenceService->score($link);

    $rankedItems[] = [
        'item' => $item,
        'relevanceScore' => $relevanceScore,
        'sourceScore' => $confidence['score'] ?? 0,
        'sourceLabel' => $confidence['label'] ?? 'Unknown',
    ];
}

usort($rankedItems, static function (array $a, array $b): int {
    return [$b['relevanceScore'], $b['sourceScore']]
        <=> [$a['relevanceScore'], $a['sourceScore']];
});

if (empty($rankedItems)) {
    return [
        'text' => 'No relevant internet evidence found. Search results existed, but they did not match the key claim context.',
        'items' => [],
    ];
}

foreach (array_slice($rankedItems, 0, 5) as $rankedItem) {
    $item = $rankedItem['item'];

    $title = $item['title'] ?? 'No title';
    $snippet = $item['snippet'] ?? 'No snippet';
    $link = $item['link'] ?? 'No link';

    $results[] = "- Title: {$title}
  Snippet: {$snippet}
  Link: {$link}
  Relevance Score: {$rankedItem['relevanceScore']}
  Source Confidence: {$rankedItem['sourceScore']}/100
  Source Type: {$rankedItem['sourceLabel']}";
}

    return [
        'text' => implode("\n\n", $results),
        'items' => $items,
    ];
}
private function scoreEvidenceRelevance(array $item, string $claim): int
{
    $title = (string) ($item['title'] ?? '');
    $snippet = (string) ($item['snippet'] ?? '');

    $haystack = mb_strtolower($title . ' ' . $snippet);
    $terms = $this->extractEvidenceTerms($claim);

    $score = 0;

    foreach ($terms as $term) {
        $term = mb_strtolower($term);

        if (str_contains($haystack, $term)) {
            $score++;
        }
    }

    return $score;
}

private function extractEvidenceTerms(string $text): array
{
    $words = preg_split('/[^\p{L}\p{N}.%]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    if (!$words) {
        return [];
    }

    $stopWords = [
        'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with',
        'from', 'that', 'this', 'these', 'those', 'while', 'and', 'or', 'but',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
        'new', 'said', 'says', 'reported', 'claimed', 'according', 'confirm',
        'confirms', 'confirmed', 'announced',
    ];

    $terms = [];

    foreach ($words as $word) {
        $cleanWord = trim($word);
        $lowerWord = mb_strtolower($cleanWord);

        if (mb_strlen($cleanWord) < 3) {
            continue;
        }

        if (in_array($lowerWord, $stopWords, true)) {
            continue;
        }

        $terms[] = $cleanWord;
    }

    return array_values(array_unique($terms));
}

    private function callSerper(string $type, string $query): array
    {
        $endpoint = $type === 'news'
            ? 'https://google.serper.dev/news'
            : 'https://google.serper.dev/search';

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => [
                'X-API-KEY' => $this->serperApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'q' => $query,
                'num' => 5,
            ],
            'timeout' => 30,
        ]);

        return $response->toArray(false);
    }



private function formatEvidenceSources(array $items, ?string $claim = null, array $relevantIndexes = []): array
{
    $sources = [];

    $relevantIndexes = array_values(array_unique(array_map('intval', $relevantIndexes)));

if (empty($relevantIndexes)) {
    return [];
}

foreach (array_slice($items, 0, 5, true) as $index => $item) {
    if (!in_array((int) $index, $relevantIndexes, true)) {
        continue;
    }

        $link = $item['link'] ?? null;

        if (!$link) {
            continue;
        }

        $title = $item['title'] ?? 'No title';
        $snippet = $item['snippet'] ?? '';
        $sourceName = $item['source'] ?? parse_url($link, PHP_URL_HOST);

        $confidence = $this->sourceConfidenceService->score($link);

        $officialDecision = $this->officialSourceDetectorService->evaluateEvidenceUrl(
            $link,
            $title,
            $snippet,
            $claim ?? ''
        );

        if (($confidence['type'] ?? 'unknown') === 'social') {
            if (!$officialDecision['official'] || ($officialDecision['confidence'] ?? 0) < 65) {
                continue;
            }
        } else {
            if (($confidence['score'] ?? 0) < 60) {
                continue;
            }
        }

        $sources[] = [
            'title' => $title,
            'link' => $link,
            'snippet' => $snippet,
            'source' => $sourceName,
            'confidenceScore' => $confidence['score'] ?? 0,
            'confidenceLabel' => $confidence['label'] ?? 'Unknown',
            'officialCategory' => $officialDecision['category'] ?? 'unknown',
            'officialConfidence' => $officialDecision['confidence'] ?? 0,
            'officialReason' => $officialDecision['reason'] ?? '',
        ];
    }

    return $sources;
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
    
    private function buildScoreBreakdown(
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
        $host = $this->extractEvidenceHost04B($source);

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

private function calculateEvidenceMatchScore04B(
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


private function calculateSourceAuthorityScore04B(
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

    $relevantItems = $this->selectRelevantEvidenceItems04B($evidenceItems, $relevantIndexes);

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
private function calculateSourceIndependenceScore04B(
    array $officialSource,
    array $evidenceItems,
    array $relevantIndexes = []
): int {
    $relevantItems = $this->selectRelevantEvidenceItems04B($evidenceItems, $relevantIndexes);

    $hosts = [];

    foreach ($relevantItems as $item) {
        $host = $this->extractEvidenceHost04B($item);
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

private function calculateRiskSafetyScore04B(string $postText): int
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

private function detectSourceDecision04B(
    array $officialSource,
    array $evidenceItems,
    array $relevantIndexes = []
): string {
    if (($officialSource['official'] ?? false) === true) {
        return 'PRIMARY_OFFICIAL';
    }

    $relevantItems = $this->selectRelevantEvidenceItems04B($evidenceItems, $relevantIndexes);

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
    $riskSafety = $this->calculateRiskSafetyScore04B($postText);

    return match (true) {
        $riskSafety >= 9 => 'LOW_RISK',
        $riskSafety >= 7 => 'MINOR_RISK',
        $riskSafety >= 4 => 'MEDIUM_RISK',
        $riskSafety >= 1 => 'HIGH_RISK',
        default => 'SEVERE_RISK',
    };
}

private function selectRelevantEvidenceItems04B(array $evidenceItems, array $relevantIndexes = []): array
{
    if (empty($relevantIndexes)) {
        return $evidenceItems;
    }

    $selected = [];

    foreach ($relevantIndexes as $index) {
        if (isset($evidenceItems[$index])) {
            $selected[] = $evidenceItems[$index];
        }
    }

    return $selected;
}

private function extractEvidenceHost04B(array $item): string
{
    $link = (string) ($item['link'] ?? '');

    if ($link !== '') {
        $host = parse_url($link, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return preg_replace('/^www\./', '', mb_strtolower($host)) ?? mb_strtolower($host);
        }
    }

    return mb_strtolower(trim((string) ($item['source'] ?? '')));
}
}
