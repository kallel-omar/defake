<?php

namespace App\Service;



class PostAnalysisService
{
    public function __construct(
    private readonly CredibilityEngineService $credibilityEngineService,
    private readonly GroqAiService $groqAiService,
    private readonly OfficialSourceDetectorService $officialSourceDetectorService,
    private readonly InternetEvidenceService $internetEvidenceService
) {
}

    public function analyze(string $url, string $postText, array $sourceContext = []): array
    {
        $internetEvidence = $this->internetEvidenceService->search($postText);

        $calculatedSourceScore = $this->credibilityEngineService->calculateSourceScore($internetEvidence);

        $officialSource = $this->officialSourceDetectorService->detect($sourceContext);

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

Evaluation criteria:
- Does the post provide evidence?
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

        if ($officialSource['official'] && $verificationScore < 20) {
            $verificationScore = 20;
        }

        $finalScore =
            $evidenceScore +
            $calculatedSourceScore +
            $languageScore +
            $verificationScore;

        if ($finalScore <= 30) {
            $finalVerdict = 'Likely Fake';
        } elseif ($finalScore <= 60) {
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