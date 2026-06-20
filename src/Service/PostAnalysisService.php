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
        private readonly GroqAiService $groqAiService,
        private readonly OfficialSourceDetectorService $officialSourceDetectorService,
        private readonly EvidenceDecisionService $evidenceDecisionService,
    ) {
    }

    public function analyze(string $url, string $postText, array $sourceContext = []): array
    {
     $internetEvidenceData = $this->searchInternetEvidence($postText);

$internetEvidence = $internetEvidenceData['text'];

$evidenceItems = $internetEvidenceData['items'];

$evidenceDecision = $this->evidenceDecisionService->decide(
    $this->extractMainClaim($postText),
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
If the internet evidence contains multiple credible sources with the same teams, competition, and date, treat the claim as supported.

Do not say the internet evidence contradicts the claim unless at least one credible source explicitly says the claim is false or gives a different date/opponent.

If credible sources confirm the match exists, Evidence Score should be at least 15 and Verification Reason should say the claim is supported by external sources.
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
25 = neutral, factual language
15 = somewhat emotional or persuasive
5 = highly emotional, manipulative, biased, or conspiratorial
0 = extreme manipulation, propaganda, or inflammatory language

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

        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'You are DeFake, a professional fact-checking AI. Return only valid JSON in English.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        if (!$content) {
            return $this->failedResult('Groq returned no content.');
        }

        $content = trim($content);
        $content = preg_replace('/^```json|```$/m', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result)) {
            return $this->failedResult($content);
        }

        $evidenceScore = max(0, min(25, (int) ($result['evidenceScore'] ?? 0)));
        $languageScore = max(0, min(25, (int) ($result['languageScore'] ?? 0)));

        if ($officialSource['official'] && $evidenceScore < 20) {
            $evidenceScore = 20;
        }

        $verificationScore = $this->credibilityEngineService->calculateVerificationScore(
            $evidenceScore,
            $calculatedSourceScore,
            $languageScore
        );

        if ($evidenceDecision['status'] === 'SUPPORTED') {
    $evidenceScore = max($evidenceScore, 20);
    $verificationScore = max($verificationScore, 20);

    $result['evidenceReason'] = $evidenceDecision['reason'];
    $result['verificationReason'] = 'The main claim is supported by multiple search results.';
}
        if ($officialSource['official']) {
        $result['evidenceReason'] =
    'This announcement was published directly by the official organization page. The page is treated as a primary source for information concerning its own activities.';

    $result['sourceReason'] = 'The Facebook source appears to be an official organization page. ' . $officialSource['reason'];

    $result['verificationReason'] = 'Because the announcement comes from the official page of the organization concerned, it is treated as directly verifiable from the primary source.';

 $result['explanation'] =
    'This post was published by the official page of the organization and concerns its own activities. Because the source is primary and directly responsible for the information being announced, the post is considered highly credible unless strong contradictory evidence exists.';}

        $finalScore =
            $evidenceScore +
            $calculatedSourceScore +
            $languageScore +
            $verificationScore;

        if ($finalScore <= 25) {
    $finalVerdict = 'Likely Fake';
} elseif ($finalScore <= 50) {
    $finalVerdict = 'Suspicious';
} else {
    $finalVerdict = 'Likely Trusted';
}

        return [
            'score' => $finalScore,
            'verdict' => $finalVerdict,

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

   

    private function searchInternetEvidence(string $postText): array
    {
        $query = $this->extractMainClaim($postText);
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

        foreach (array_slice($items, 0, 5) as $item) {
            $title = $item['title'] ?? 'No title';
            $snippet = $item['snippet'] ?? 'No snippet';
            $link = $item['link'] ?? 'No link';

            $confidence = $this->sourceConfidenceService->score($link);

            $results[] = "- Title: {$title}
  Snippet: {$snippet}
  Link: {$link}
  Source Confidence: {$confidence['score']}/100
  Source Type: {$confidence['label']}";
        }

        return [
    'text' => implode("\n\n", $results),
    'items' => $items,
];
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

    private function extractMainClaim(string $postText): string
    {
        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                 $result['explanation'] =
'content' => 'Convert the post into ONE concise Google search query. Include all teams/entities, exact dates, competition names, places, and numbers found in the post. Never remove dates. Never return an explanation. Never start with phrases like "Here is". Return only the search query text.',            ],
            [
                'role' => 'user',
                'content' => $postText,
            ],
        ]);

        if (!$content) {
            return $postText;
        }

        return trim($content);
    }

    private function failedResult(string $explanation): array
    {
        return [
            'score' => 0,
            'verdict' => 'Analysis Failed',
            'evidenceScore' => 0,
            'sourceScore' => 0,
            'languageScore' => 0,
            'verificationScore' => 0,
            'evidenceReason' => '',
            'sourceReason' => '',
            'languageReason' => '',
            'verificationReason' => '',
            'explanation' => $explanation,
        ];
    }
}