<?php

namespace App\Service;

class ClaimExtractionService
{
    public function __construct(
        private readonly GeminiAiService $geminiAiService
    ) {
    }

    public function extract(string $postText): array
    {
        $postText = trim($postText);

        if ($postText === '') {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        // Reduce token usage for free Gemini quota
        $postText = mb_substr($postText, 0, 6000);

        $prompt = <<<PROMPT
You are DeFake's claim extraction engine.

Your job has TWO steps:

1. Decide if the Facebook post contains at least one clear verifiable factual claim.
2. If yes, extract up to 3 important factual claims.

The post may be in Tunisian Arabic, Maghrebi Arabic, Arabic, French, English, or mixed language.

Decision rules:

Classify the post as fact_checkable=true only when it contains at least one clear factual claim that can be checked with external evidence.

A factual claim usually has:

1. A subject:
A person, organization, company, club, ministry, government body, institution, place, country, city, product, event, or public figure.

2. A factual assertion:
Something happened, was announced, was decided, was denied, was approved, was cancelled, was signed, was launched, was arrested, died, resigned, was appointed, increased, decreased, won, lost, opened, closed, banned, published, scheduled, or officially stated.

3. A checkable detail:
A date, time, location, number, amount, score, role, title, source, document, event, opponent, contract, law, public decision, or official announcement.

Classify as fact_checkable=true when the claim can be checked using:
- official sources
- reliable media
- public records
- official websites
- documents
- databases
- search results
- public announcements

Do not limit the decision to one domain. Accept verifiable claims from any category:
- politics
- sports
- economy
- business
- technology
- health
- education
- justice
- security
- transport
- weather
- entertainment
- public services
- local news
- international news
- social issues
- company announcements
- organization announcements
- official announcements

Classify as fact_checkable=false when the post is only:
- opinion
- insult
- emotion
- joke
- sarcasm
- prediction
- question
- slogan
- vague criticism
- personal reaction
- general anger
- political banter
- sports banter
- moral judgment without a specific checkable fact

Important:
- Mentioning a famous person, club, ministry, country, or company is not enough.
- There must be a concrete factual assertion about that subject.
- Future events can be verifiable if they are announced, scheduled, planned, listed, or officially decided.
- Future guesses are not verifiable.
- Do not invent missing facts.
- Do not force a claim if the post is vague.
- Ignore attribution phrases like "Reuters reported", "BBC said", "according to sources", or "sources confirmed".
- Extract the underlying factual claim, not a claim about the reporting source.

Important Tunisian/Maghrebi Arabic rules:
- Do not translate slang literally.
- Words like "طحين", "طحانة", "بارازيت", "بني كرغول", "منحل", "كز" are often insults/slang, not factual claims.
- If the post is only anger, criticism, mockery, or opinion, return fact_checkable=false.
- Preserve names, dates, numbers, clubs, ministries, federations, companies, and locations exactly as written.
- Preserve the original language of the claim as much as possible.
- Do not replace specific entities with generic terms.

Examples of verifiable claims:
- "The ministry announced that registration will open on 1 July."
- "The company launched a new AI tool today."
- "The match will be played at 18:00 in Kansas City."
- "The court sentenced the former official to 3 years in prison."
- "Fuel prices increased by 5%."
- "The university announced that exams will start on 10 June."
- "A train accident happened in Sousse this morning."
- "The player signed a two-year contract with the club."
- "The government approved a new finance law."
- "The hospital opened a new emergency department."
- "The president will visit France on Monday."
- "A company signed a partnership agreement with a ministry."

Examples of non-verifiable content:
- "This minister is useless."
- "The federation is corrupt."
- "Tunisia will win 3-0."
- "This company will become the biggest in Africa."
- "The player will be amazing next season."
- "What is happening in this country?"
- "Shame on them."
- "They destroyed everything."
- "Worst decision ever."
- "The country is finished."

Return ONLY valid JSON with this exact structure:

{
  "fact_checkable": false,
  "reason": "short reason",
  "claims": []
}

If the post contains clear factual claims, use:

{
  "fact_checkable": true,
  "reason": "short reason",
  "claims": [
    "claim 1",
    "claim 2",
    "claim 3"
  ]
}

Never force claims.
Return max 3 claims.
PROMPT;
dump('POST TEXT SENT TO GEMINI:');
dump($postText);

        $content = $this->geminiAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown. No explanation outside JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt . "\n\nFacebook post:\n" . $postText,
            ],
        ]);
        dump('GEMINI CLAIM EXTRACTION RESPONSE:');
dump($content);

        if (!$content) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        $result = $this->decodeJson($content);

        if (!is_array($result)) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        $factCheckable = (bool) ($result['fact_checkable'] ?? false);

        if (!$factCheckable) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        $claims = $result['claims'] ?? [];

        if (!is_array($claims)) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        $claims = array_values(array_filter(array_map(
            fn ($claim) => trim((string) $claim),
            $claims
        )));

        if (empty($claims)) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        return array_slice($claims, 0, 3);
    }

    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $content = trim($content);

        $data = json_decode($content, true);

        if (is_array($data)) {
            return $data;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}