<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PostAnalysisService
{
    public function __construct(
        private readonly string $serperApiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly CredibilityEngineService $credibilityEngineService,
        private readonly SourceConfidenceService $sourceConfidenceService,

        private readonly OfficialSourceDetectorService $officialSourceDetectorService,
        private readonly EvidenceDecisionService $evidenceDecisionService,
        private readonly ClaimExtractionService $claimExtractionService,
private readonly ClaimVerificationService $claimVerificationService,
    ) {
    }

    public function analyze(string $url, string $postText, array $sourceContext = []): array
    {
          $originalPostText = $postText;
        $claims = $this->claimExtractionService->extract($postText);
$mainClaim = trim((string) ($claims[0] ?? ''));

if ($mainClaim === '' || $mainClaim === 'NO_VERIFIABLE_CLAIM') {
    return [
        'score' => 0,
        'verdict' => 'NOT_VERIFIABLE',
        'mainClaim' => null,
        'evidenceSources' => [],
        'evidenceScore' => 0,
        'sourceScore' => 0,
        'languageScore' => 0,
        'verificationScore' => 0,
        'evidenceReason' => 'No evidence search was performed because no clear factual claim was detected.',
        'sourceReason' => 'Source analysis was skipped because the post is not a factual news claim.',
        'languageReason' => 'The post appears to be opinion, insult, sarcasm, or emotional commentary.',
        'verificationReason' => 'Verification was skipped because there is no specific factual claim to check.',
        'explanation' => 'This post does not contain a clear verifiable factual claim. It appears to be opinion, insult, sarcasm, or emotional commentary rather than news.',
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

        $calculatedSourceScore = $this->credibilityEngineService->calculateSourceScore($internetEvidence);

        $officialSource = $this->officialSourceDetectorService->detect($sourceContext, $postText);

        if ($officialSource['official']) {
            $calculatedSourceScore = 25;
        }

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

       $verificationResult = $this->claimVerificationService->verify(
    $mainClaim,
    $internetEvidence,
    $originalPostText
);

$result = [
    'evidenceScore' => match ($verificationResult['verdict'] ?? 'INSUFFICIENT_EVIDENCE') {
        'SUPPORTED' => 25,
        'CONTRADICTED' => 5,
        default => 5,
    },

    'languageScore' => 20,
    'evidenceReason' => $verificationResult['explanation'] ?? 'Groq analyzed the available evidence.',
    'sourceReason' => $officialReason,
    'languageReason' => 'Language analysis was kept neutral because Groq was used mainly for evidence verification.',
    'verificationReason' => $verificationResult['explanation'] ?? 'Groq completed the credibility analysis.',
    'explanation' => $verificationResult['explanation'] ?? 'Groq completed the credibility analysis.',
    'contextMatch' => $verificationResult['contextMatch'] ?? false,
    'contextReason' => $verificationResult['contextReason'] ?? '',
    'claimContextComplete' => $verificationResult['claimContextComplete'] ?? false,
    'evidenceAddsNewContext' => $verificationResult['evidenceAddsNewContext'] ?? true,
];
$verificationContextSafe =
    ($verificationResult['contextMatch'] ?? false) === true
    && ($verificationResult['claimContextComplete'] ?? false) === true
    && ($verificationResult['evidenceAddsNewContext'] ?? true) === false;
$evidenceScore = max(0, min(25, (int) ($result['evidenceScore'] ?? 0)));
$languageScore = max(0, min(25, (int) ($result['languageScore'] ?? 0)));

$languageReason = mb_strtolower((string) ($result['languageReason'] ?? ''));

if (
    $languageScore < 15 &&
    (
        str_contains($languageReason, 'neutral') ||
        str_contains($languageReason, 'factual') ||
        str_contains($languageReason, 'clear') ||
        str_contains($languageReason, 'informative')
    )
) {
    $languageScore = 20;
}

if (
    $languageScore > 10 &&
    (
        str_contains($languageReason, 'manipulative') ||
        str_contains($languageReason, 'inflammatory') ||
        str_contains($languageReason, 'propaganda') ||
        str_contains($languageReason, 'insult') ||
        str_contains($languageReason, 'highly emotional')
    )
) {
    $languageScore = 5;
}

        if ($officialSource['official'] && $evidenceScore < 20) {
            $evidenceScore = 20;
        }

        $verificationScore = $this->credibilityEngineService->calculateVerificationScore(
            $evidenceScore,
            $calculatedSourceScore,
            $languageScore
        );

       if ($evidenceDecision['status'] === 'SUPPORTED' && $verificationContextSafe) {
    $evidenceScore = max($evidenceScore, 20);
    $verificationScore = max($verificationScore, 20);

    $result['evidenceReason'] = $evidenceDecision['reason'];
    $result['verificationReason'] = 'The main claim is supported by relevant external sources in the same context.';
}

if ($evidenceDecision['status'] === 'SUPPORTED' && !$verificationContextSafe) {
    $evidenceScore = min($evidenceScore, 10);
    $verificationScore = min($verificationScore, 8);

    $result['evidenceReason'] = $result['contextReason'] ?? 'The evidence appears related but does not safely match the original claim context.';
    $result['verificationReason'] = 'The evidence was not accepted as support because the claim context was incomplete or the evidence added new context.';
    $result['explanation'] = 'DeFake found related evidence, but it does not safely verify the exact claim in the same context. The post should be treated with caution.';
}

if ($evidenceDecision['status'] === 'PARTIALLY_SUPPORTED') {
    $evidenceScore = min($evidenceScore, 15);
    $verificationScore = min($verificationScore, 10);

    $result['evidenceReason'] = $evidenceDecision['reason'];
    $result['verificationReason'] = 'The evidence is related, but it does not fully confirm the specific claim.';
    $result['explanation'] = 'DeFake found some related sources, but they do not fully confirm the specific claim. The post should be treated with caution until a directly relevant source confirms it.';
}

if (in_array($evidenceDecision['status'], ['UNSUPPORTED', 'UNRELATED'], true)) {
    $evidenceScore = min($evidenceScore, 10);
    $verificationScore = min($verificationScore, 8);

    $result['evidenceReason'] = $evidenceDecision['reason'];
    $result['verificationReason'] = 'The available sources do not confirm the specific claim.';
    $result['explanation'] = 'DeFake could not find relevant sources confirming the specific claim. Related or trusted sources may exist, but they do not verify this exact claim.';
}

if ($evidenceDecision['status'] === 'CONTRADICTED') {
    $evidenceScore = min($evidenceScore, 5);
    $verificationScore = min($verificationScore, 5);

    $result['evidenceReason'] = $evidenceDecision['reason'];
    $result['verificationReason'] = 'The available evidence appears to contradict the claim.';
    $result['explanation'] = 'DeFake found evidence that appears to contradict the claim. The post is therefore considered high risk unless stronger evidence proves otherwise.';
}

       $officialCategory = $officialSource['category'] ?? 'unknown';
$officialConfidence = (int) ($officialSource['confidence'] ?? 0);

$isStrongPrimarySource =
    $officialSource['official']
    && $officialConfidence >= 85
    && in_array($officialCategory, [
        'government',
        'ministry',
        'public_authority',
        'company',
        'club',
        'federation',
        'organization',
    ], true);

if (
    $isStrongPrimarySource &&
    $evidenceDecision['status'] !== 'CONTRADICTED'
) {
    $evidenceScore = max($evidenceScore, 25);
    $calculatedSourceScore = 25;
    $verificationScore = max($verificationScore, 25);

    $result['evidenceReason'] = 'The post appears to come from a strongly verified official source, so the page itself can be treated as primary evidence for its own announcement.';

    $result['sourceReason'] = 'The Facebook source appears to be a strongly verified official organization page. ' . $officialSource['reason'];

    $result['verificationReason'] = 'Because the announcement appears to come from a strongly verified official page of the organization concerned, it is treated as directly verifiable unless strong contradictory evidence exists.';

    $result['explanation'] = 'This post appears to come from a strongly verified official source and concerns the organization’s own activity. It is considered credible unless reliable contradictory evidence is found.';
}

        $finalScore =
            $evidenceScore +
            $calculatedSourceScore +
            $languageScore +
            $verificationScore;
            if ($evidenceDecision['status'] === 'CONTRADICTED') {
    $finalScore = min($finalScore, 25);
}

if (
    !$isStrongPrimarySource &&
    in_array($evidenceDecision['status'], ['UNSUPPORTED', 'UNRELATED'], true)
) {
    $finalScore = min($finalScore, 50);
}

if (
    !$isStrongPrimarySource &&
    $evidenceDecision['status'] === 'PARTIALLY_SUPPORTED'
) {
    $finalScore = min($finalScore, 50);
}

        if ($finalScore <= 25) {
            $finalVerdict = 'Likely Fake';
        } elseif ($finalScore <= 50) {
            $finalVerdict = 'Suspicious';
        } else {
            $finalVerdict = 'Likely Trusted';
        }
        if (
    $officialSource['official']
    && $finalVerdict === 'Likely Trusted'
    && $evidenceDecision['status'] !== 'CONTRADICTED'
) {
    $result['explanation'] = 'This post appears to come from an official source and concerns the organization’s own activity. It is considered credible unless reliable contradictory evidence is found.';

    $result['verificationReason'] = 'Because the announcement appears to come from an official page of the organization concerned, it is treated as directly verifiable unless strong contradictory evidence exists.';

    $result['evidenceReason'] = 'The Facebook page itself can be treated as primary evidence for its own announcement.';

    $result['sourceReason'] = 'The Facebook source appears to be an official organization page. ' . $officialReason;
}

        return [
            'score' => $finalScore,
            'verdict' => $finalVerdict,
            'mainClaim' => $mainClaim,
            'evidenceSources' => $this->formatEvidenceSources(
    $evidenceItems,
    $mainClaim,
    $evidenceDecision['relevantIndexes'] ?? []
),

            'evidenceScore' => $evidenceScore,
            'sourceScore' => $calculatedSourceScore,
            'languageScore' => $languageScore,
            'verificationScore' => $verificationScore,

            'evidenceReason' => $result['evidenceReason'] ?? '',
            'sourceReason' => $officialSource['official']
                ? 'The Facebook source appears to be an official organization page. ' . $officialReason
                : ($result['sourceReason'] ?? $officialReason),
            'languageReason' => $result['languageReason'] ?? '',
            'verificationReason' => $result['verificationReason'] ?? '',

            'explanation' => $result['explanation'] ?? 'No explanation provided.',
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
            'explanation' => $explanation,
            'mainClaim' => null,
        ];
    }
}
