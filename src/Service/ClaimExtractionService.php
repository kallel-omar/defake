<?php

namespace App\Service;

class ClaimExtractionService
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function extract(string $postText): array
    {
        $postText = trim($postText);

        if ($postText === '') {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        // Reduce token usage for AI model limits
        $postText = mb_substr($postText, 0, 6000);

        $prompt = <<<PROMPT
You are DeFake's claim extraction engine.

Your job has TWO steps:

1. Decide if the post contains at least one clear verifiable factual claim.
2. If yes, extract up to 3 important factual claims.

The post may be in any language, including Arabic, Tunisian Arabic, Maghrebi Arabic, French, English, or mixed language.

Decision rules:

Classify the post as fact_checkable=true only when it contains at least one clear factual claim that can be checked with external evidence.

A factual claim usually has:

1. A subject:
   A person, organization, company, club, ministry, government body, institution, place, country, city, product, event, public figure, team, source, website, document, law, decision, or public entity.

2. A factual assertion:
   Something happened, was announced, was decided, was denied, was approved, was cancelled, was signed, was launched, was arrested, died, resigned, was appointed, increased, decreased, won, lost, opened, closed, banned, published, scheduled, transferred, renewed, terminated, joined, left, created, removed, or officially stated.

This must work for any domain and any language.

3. A checkable detail:
   A date, time, location, number, amount, score, role, title, source, document, event, opponent, contract, law, public decision, official announcement, transfer, agreement, appointment, result, price, percentage, name, or organization.

Classify as fact_checkable=true when the claim can be checked using:

* official sources
* reliable media
* public records
* official websites
* documents
* databases
* search results
* public announcements
* match records
* company records
* government or organization statements

Do not limit the decision to one domain. Accept verifiable claims from any category:

* politics
* sports
* economy
* business
* technology
* health
* education
* justice
* security
* transport
* weather
* entertainment
* public services
* local news
* international news
* social issues
* company announcements
* organization announcements
* official announcements

Classify as fact_checkable=false when the post is only:

* opinion
* insult
* emotion
* joke
* sarcasm
* prediction without evidence
* question
* slogan
* vague criticism
* personal reaction
* general anger
* political banter
* sports banter
* moral judgment without a specific checkable fact

Important:

* Mentioning a famous person, club, ministry, country, company, or organization is not enough.
* There must be a concrete factual assertion about that subject.
* Future events can be verifiable only if they are announced, scheduled, planned, listed, reported as a decision, or officially decided.
* Future guesses are not verifiable.
* Do not invent missing facts.
* Do not force a claim if the post is vague.
* Ignore attribution phrases like "Reuters reported", "BBC said", "according to sources", "sources confirmed", "مصادر", "حسب", "قالت الصحيفة", or similar.
* Extract the underlying factual claim, not a claim about the reporting source.

Claim extraction format rules:
* Prefer extracting one main claim when the post is about one event.
* Do not split supporting details into separate claims if they describe the same event.
* If several sentences all support the same story, merge them into one concise main claim.
* Only extract multiple claims when they are truly separate factual events.
* Do not create a new claim from a detail like "negotiations are advanced" if it only supports the main transfer/announcement story.
* Do not rewrite grammar or create new verb forms. Keep the original wording as much as possible.
* Do not generate words that are not present in the post unless absolutely necessary for a short grammatical connector.
* Extract claims as close as possible to the original wording in the post.
* A claim must be copied from the post text as much as possible, not rewritten from outside knowledge.
* Do not add any person, club, company, country, city, ministry, organization, date, number, or event that is not explicitly present in the post.
* Do not translate entity names.
* Do not replace an ambiguous entity with a guessed specific entity.
* Do not use outside knowledge to complete the claim.
* It is allowed to shorten a long sentence, but only by removing extra words, not by adding new facts or new entities.
* If the claim needs extra context that is not present in the post, keep the original ambiguous wording.
* If you are not sure, preserve the exact words from the post.
* Preserve names, dates, numbers, clubs, ministries, federations, companies, organizations, and locations exactly as written.
* Preserve the original language of the claim as much as possible.
* Do not replace specific entities with generic terms.
* Do not replace generic or ambiguous entities with specific entities.

Important Arabic / Maghrebi Arabic rules:

* Do not translate slang literally.
* Insults, mockery, emotion, or anger are not factual claims unless they include a specific checkable event or assertion.
* Words like "طحين", "طحانة", "بارازيت", "منحل", "كز", "خونة", "فاسدين", and similar slang are usually opinion/insult, not factual claims.
* If the post is only anger, criticism, mockery, or opinion, return fact_checkable=false.
* Keep Arabic names and entities exactly as written.
* Do not normalize or guess club names, city names, people names, or organization names.

Examples of verifiable claims:

* "The ministry announced that registration will open on 1 July."
* "The company launched a new AI tool today."
* "The match will be played at 18:00 in Kansas City."
* "The court sentenced the former official to 3 years in prison."
* "Fuel prices increased by 5%."
* "The university announced that exams will start on 10 June."
* "A train accident happened this morning."
* "The player signed a two-year contract with the club."
* "The government approved a new finance law."
* "The hospital opened a new emergency department."
* "The president will visit France on Monday."
* "A company signed a partnership agreement with a ministry."
* "The club is close to signing the player."
* "The organization denied the report."
* "The match was postponed."
* "The coach resigned."

Examples of non-verifiable content:

* "This minister is useless."
* "The federation is corrupt."
* "Tunisia will win 3-0."
* "This company will become the biggest in Africa."
* "The player will be amazing next season."
* "What is happening in this country?"
* "Shame on them."
* "They destroyed everything."
* "Worst decision ever."
* "The country is finished."
* "The club is a joke."
* "Everyone knows the truth."

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
Return max 3 claims, but prefer 1 claim when the post is about one main event.
Only return multiple claims when they are independent facts, not repeated details of the same story.
Do not return markdown.
Do not return text outside JSON.

PROMPT;


        $content = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown. No explanation outside JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt . "\n\nFacebook post:\n" . $postText,
            ],
        ]);


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

        $claims = $this->cleanExtractedClaims($claims, $postText);

        if (empty($claims)) {
            return ['NO_VERIFIABLE_CLAIM'];
        }

        return array_slice($claims, 0, 3);
    }
    private function cleanExtractedClaims(array $claims, string $postText): array
    {
        $claims = array_values(array_unique(array_filter(array_map(
            fn ($claim) => trim((string) $claim),
            $claims
        ))));

        if (count($claims) <= 1) {
            return $claims;
        }

        $postTerms = $this->extractImportantTerms($postText);
        $filteredClaims = [];

        foreach ($claims as $claim) {
            $claimTerms = $this->extractImportantTerms($claim);
            $missingTerms = array_diff($claimTerms, $postTerms);

            // Reject claims that add too many important words not found in the original post.
            if (count($claimTerms) >= 3 && count($missingTerms) > 2) {
                continue;
            }

            $filteredClaims[] = $claim;
        }

        if (!empty($filteredClaims)) {
            $claims = $filteredClaims;
        }

        if (count($claims) <= 1) {
            return $claims;
        }

        $claims = $this->removeSupportingDetailClaims($claims);

        if (count($claims) <= 1) {
            return $claims;
        }

        // If claims are only details of the same story, keep only the strongest main claim.
        if ($this->claimsLookLikeSameStory($claims)) {
            return [$this->selectMainClaim($claims, $postText)];
        }

        return $claims;
    }
    private function removeSupportingDetailClaims(array $claims): array
    {
        $mainClaims = array_values(array_filter(
            $claims,
            fn (string $claim) => !$this->looksLikeSupportingDetail($claim)
        ));

        // Safety: if everything looks like supporting detail, keep the original claims.
        if (empty($mainClaims)) {
            return $claims;
        }

        return $mainClaims;
    }

    private function looksLikeSupportingDetail(string $claim): bool
    {
        $terms = $this->extractImportantTerms($claim);

        if (count($terms) > 6) {
            return false;
        }

        return preg_match(
            '/(details?|final stages?|advanced stages?|remaining details?|agreement details?|negotiations?|مفاوضات|تفاصيل|مرحلة|مرحله|مراحل|مراحلها|الأخيرة|الاخيرة|اخيرة|الرسمي|الرسمية|رسمي|الأيام|الايام|القادمة|القادمه|انتظار|التأكيد|التاكيد)/iu',
            $claim
        ) === 1;
    }
    private function claimsLookLikeSameStory(array $claims): bool
    {
        $baseTerms = $this->extractImportantTerms($claims[0] ?? '');

        if (count($baseTerms) < 2) {
            return false;
        }

        foreach (array_slice($claims, 1) as $claim) {
            $terms = $this->extractImportantTerms($claim);
            $overlap = array_intersect($baseTerms, $terms);

            if (count($overlap) < 2) {
                return false;
            }
        }

        return true;
    }

    private function selectMainClaim(array $claims, string $postText): string
    {
        $postTerms = $this->extractImportantTerms($postText);

        $bestClaim = $claims[0];
        $bestScore = PHP_INT_MIN;

        foreach ($claims as $claim) {
            $claimTerms = $this->extractImportantTerms($claim);
            $missingTerms = array_diff($claimTerms, $postTerms);

            $score = count($claimTerms);
            $score -= count($missingTerms) * 3;

            if ($this->containsStrongFactVerb($claim)) {
                $score += 5;
            }

            if (mb_strlen($claim) < 25) {
                $score -= 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestClaim = $claim;
            }
        }

        return $bestClaim;
    }

    private function containsStrongFactVerb(string $text): bool
    {
        return preg_match(
            '/(announced|approved|signed|transferred|joined|launched|opened|closed|denied|confirmed|resigned|appointed|scheduled|postponed|cancelled|won|lost|increased|decreased|صفقة|انتقال|تعاقد|وقع|وقّع|انضم|اقترب|حسم|اعلن|أعلن|اعلنت|أعلنت|قرر|صادق|الغى|ألغى|تاجل|تأجل|فاز|خسر|ارتفع|انخفض|استقال|عين|عيّن|تعيين)/iu',
            $text
        ) === 1;
    }

    private function extractImportantTerms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}.%]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!$words) {
            return [];
        }

        $stopWords = [
            // English
            'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with',
            'from', 'that', 'this', 'these', 'those', 'and', 'or', 'but', 'is',
            'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
            'will', 'would', 'could', 'should', 'may', 'might', 'new',

            // French
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'dans', 'sur',
            'avec', 'pour', 'par', 'et', 'ou', 'est', 'sont', 'sera',

            // Arabic / Maghrebi Arabic
            'من', 'الى', 'إلى', 'على', 'في', 'عن', 'مع', 'هذا', 'هذه', 'ذلك',
            'تلك', 'الذي', 'التي', 'كان', 'كانت', 'يكون', 'سوف', 'قد', 'لقد',
            'قبل', 'بعد', 'خلال', 'دون', 'غير', 'فقط', 'حسب', 'مصادر',
            'مصدر', 'قال', 'قالت', 'جريده', 'جريدة', 'صحيفه', 'صحيفة',
            'التونسيه', 'التونسية', 'الحالي', 'الحاليه', 'الحالية',
        ];

        $terms = [];

        foreach ($words as $word) {
            $term = $this->normalizeTerm($word);

            if (mb_strlen($term) < 3) {
                continue;
            }

            if (in_array($term, $stopWords, true)) {
                continue;
            }

            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function normalizeTerm(string $term): string
    {
        $term = mb_strtolower($term);

        $term = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $term);
        $term = str_replace('ى', 'ي', $term);
        $term = str_replace('ة', 'ه', $term);

        $term = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $term) ?? $term;

        $term = trim($term, " \t\n\r\0\x0B.,;:!?،؛؟()[]{}\"'«»");

        foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $prefix) {
            if (str_starts_with($term, $prefix) && mb_strlen($term) > mb_strlen($prefix) + 2) {
                return mb_substr($term, mb_strlen($prefix));
            }
        }

        return $term;
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
