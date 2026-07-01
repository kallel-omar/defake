<?php

declare(strict_types=1);

namespace App\Service;

class ClaimExtractionService
{
    private const NO_VERIFIABLE_CLAIM = 'NO_VERIFIABLE_CLAIM';

    /**
     * Keep this reasonable for MVP cost and Groq token limits.
     * The original full post is still stored elsewhere; this is only the AI understanding input.
     */
    private const MAX_AI_POST_CHARS = 10000;

    /**
     * DeFake MVP rule:
     * verify one main claim by default, and a second claim only if it is clearly independent.
     */
    private const MAX_RETURNED_CLAIMS = 2;

    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function extract(string $postText): array
    {
        $postText = trim($postText);

        if ($postText === '') {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $postText = $this->limitTextForAi($postText);
        $claimExtractionText = $this->prepareClaimExtractionText($postText);

        if ($claimExtractionText === '') {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $prompt = <<<PROMPT
You are DeFake's post understanding and main claim selection engine.

Your job has THREE steps:

1. Understand what the post is mainly about.
2. Classify the post content type.
3. If it contains factual claims, return the most important verifiable claim DeFake should verify.

The post may be in any language, including Arabic, Tunisian Arabic, Maghrebi Arabic, French, English, or mixed language.
Messy input tolerance rule:
- The post may be short, badly written, misspelled, missing accents, or written with weak grammar.
- Try to understand the intended public factual claim before deciding it is not verifiable.
- Do not reject a claim only because a person name, club name, organization name, or action is misspelled.
- If the text contains a possible public subject plus a factual action, classify it as fact_checkable=true and return the best claim using the wording present in the post.
- Examples of factual actions include: died, injured, signed, joined, left, transferred, arrested, appointed, resigned, suspended, banned, announced, denied, won, lost, postponed, cancelled, approved, or sanctioned.
- Do not invent missing facts or replace misspelled entities with guessed official names.
- Keep the original ambiguous or misspelled wording when needed.
- Still return fact_checkable=false for jokes, insults, pure opinions, personal feelings, spam, or vague text with no subject and no factual action.
Core rule:
Do NOT extract every possible sentence.
Select the main claim that best represents what DeFake should verify.

Allowed content_type values:
- claim
- rumor_claim
- multi_claim_news
- opinion
- joke_or_mockery
- question
- advertisement
- personal_update
- vague
- no_claim
- mixed

Classify fact_checkable=true when the post contains at least one clear OR reasonably understandable public factual claim that can be checked with external evidence.

A factual claim usually has:
1. A subject:
   A person, organization, company, club, ministry, government body, institution, place, country, city, product, event, public figure, team, source, website, document, law, decision, or public entity.

2. A factual assertion:
   Something happened, was announced, was decided, was denied, was approved, was cancelled, was signed, was launched, was arrested, died, resigned, was appointed, increased, decreased, won, lost, opened, closed, banned, published, scheduled, transferred, renewed, terminated, joined, left, created, removed, or officially stated.

3. A checkable detail:
   A date, time, location, number, amount, score, role, title, source, document, event, opponent, contract, law, public decision, official announcement, transfer, agreement, appointment, result, price, percentage, name, or organization.

Accept verifiable claims from any category:
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
- mockery
- sarcasm without a specific factual event
- vague commentary
- emotional reaction
- prediction
- wish
- prayer
- joke
- meme text
- question without factual assertion
- personal feeling
- generic accusation without a specific event
- advertisement without a factual public claim

Important source attribution rule:
Ignore attribution phrases like "Reuters reported", "BBC said", "according to sources", "sources confirmed", "مصادر", "حسب", "قالت الصحيفة", or similar.
Return the underlying factual claim, not a claim about the reporting source.

Main claim selection rules:
- Return one main_claim by default.
- Return a secondary claim only when it is a separate important event, not a supporting detail.
- Do not split supporting details into separate claims if they describe the same event.
- If several sentences all support the same story, merge/select one concise main claim.
- Do not create a separate claim from details like negotiations, coming days, required documents, agreement details, or confirmation if they only support the main story.
- Do not verify the summary. The main_claim is what will be verified.
- The summary is only for understanding/debugging.

Wording rules:
- Keep the claim as close as possible to the original post wording.
- A claim must be a complete factual assertion, not only a noun phrase, headline fragment, or topic.
- Preserve the core structure of the claim: who/what did what, to whom/what, and the key checkable detail when present.
- If the original post includes an actor, source, decision-maker, organization, person, club, company, ministry, court, federation, institution, government body, public figure, team, country, city, website, document, or official page, keep it in the claim.
- If the original post includes an action such as announced, decided, signed, denied, approved, cancelled, postponed, appointed, resigned, arrested, sentenced, launched, increased, decreased, won, lost, transferred, joined, renewed, terminated, published, opened, closed, banned, or confirmed, keep that action in the claim.
- If the original post includes a target/object such as a person, role, law, match, product, contract, transfer, sanction, price, amount, date, number, place, result, event, company, institution, or document, keep it in the claim.
- Preserve important qualifiers that change the meaning, such as "signed", "close to signing", "denied", "approved", "officially announced", "according to sources", "for two seasons", "on Sunday", "by 5%", "in Tunis", or "worth 1 million".
- Do not shorten a complete claim into only its event/topic.
- Do not remove the actor if removing it makes the claim harder to verify.
- Do not remove dates, numbers, amounts, locations, contract duration, scores, names, institutions, or official decision-makers when they are present in the post.
- Do not rewrite grammar unless needed for a short clear claim.
- Do not add any person, club, company, country, city, ministry, organization, date, number, or event that is not explicitly present in the post.
- Do not translate entity names.
- Do not replace an ambiguous entity with a guessed specific entity.
- Do not use outside knowledge to complete the claim.
- It is allowed to shorten a long sentence, but only by removing extra words, not by adding new facts or new entities.
- If the claim needs extra context that is not present in the post, keep the original ambiguous wording.
- Preserve names, dates, numbers, clubs, ministries, federations, companies, organizations, and locations exactly as written.
- Preserve the original language of the claim as much as possible.

Important Arabic / Maghrebi Arabic rules:
- Do not translate slang literally.
- Insults, mockery, emotion, or anger are not factual claims unless they include a specific checkable event or assertion.
- Words like "طحين", "طحانة", "بارازيت", "منحل", "كز", "خونة", "فاسدين", and similar slang are usually opinion/insult, not factual claims.
- If the post is only anger, criticism, mockery, or opinion, return fact_checkable=false.
- Keep Arabic names and entities exactly as written.
- Do not normalize or guess club names, city names, people names, or organization names.

Examples of verifiable main claims:
- "The ministry announced that registration will open on 1 July."
- "The company launched a new AI tool today."
- "The match will be played at 18:00 in Kansas City."
- "The court sentenced the former official to 3 years in prison."
- "Fuel prices increased by 5%."
- "The university announced that exams will start on 10 June."
- "A train accident happened this morning."
- "The player signed a two-year contract with the club."
- "The government approved a new finance law."
- "The hospital opened a new emergency department."
- "The president will visit France on Monday."
- "A company signed a partnership agreement with a ministry."
- "The club is close to signing the player."
- "The organization denied the report."
- "The match was postponed."
- "The coach resigned."

Examples of non-verifiable content:
- "This minister is useless."
- "The federation is corrupt."
- "Tunisia will win 3-0."
- "This company will become the biggest in Africa."
- "The player will be amazing next season."
- "What is happening to this country?"
- "Shame on them."
- "The media is terrible."

Return ONLY valid JSON with this exact structure when not fact-checkable:

{
  "content_type": "opinion",
  "fact_checkable": false,
  "summary": "short neutral summary of the post",
  "reason": "short reason",
  "main_claim": null,
  "secondary_claims": []
}

Return ONLY valid JSON with this exact structure when fact-checkable:

{
  "content_type": "claim",
  "fact_checkable": true,
  "summary": "short neutral summary of the post",
  "reason": "short reason",
  "main_claim": "the most important verifiable claim from the post",
  "secondary_claims": []
}

For multi-claim posts, use at most one secondary claim:

{
  "content_type": "multi_claim_news",
  "fact_checkable": true,
  "summary": "short neutral summary of the post",
  "reason": "short reason",
  "main_claim": "most important verifiable claim",
  "secondary_claims": [
    "second separate important verifiable claim"
  ]
}

Never force claims.
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
                'content' => $prompt . "\n\nPost:\n" . $claimExtractionText,
            ],
        ]);

        if (!$content) {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $result = $this->decodeJson($content);

        if (!is_array($result)) {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $factCheckable = ($result['fact_checkable'] ?? null) === true;

        if (!$factCheckable) {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $claims = $this->claimsFromAiResult($result);

        if (empty($claims)) {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        $claims = $this->cleanExtractedClaims($claims, $postText);

        if (empty($claims)) {
            return [self::NO_VERIFIABLE_CLAIM];
        }

        return array_slice($claims, 0, self::MAX_RETURNED_CLAIMS);
    }

    private function limitTextForAi(string $postText): string
    {
        $postText = trim($postText);

        if (mb_strlen($postText) <= self::MAX_AI_POST_CHARS) {
            return $postText;
        }

        $headLength = (int) floor(self::MAX_AI_POST_CHARS * 0.75);
        $tailLength = self::MAX_AI_POST_CHARS - $headLength;

        return trim(
            mb_substr($postText, 0, $headLength)
            . "\n\n[... trimmed long post for AI token safety ...]\n\n"
            . mb_substr($postText, -$tailLength)
        );
    }

    private function claimsFromAiResult(array $result): array
    {
        $claims = [];

        $mainClaim = $this->normalizeAiClaim($result['main_claim'] ?? null);

        if ($mainClaim !== null) {
            $claims[] = $mainClaim;
        }

        $secondaryClaims = $result['secondary_claims'] ?? [];

        if (is_array($secondaryClaims)) {
            foreach ($secondaryClaims as $secondaryClaim) {
                $normalizedClaim = $this->normalizeAiClaim($secondaryClaim);

                if ($normalizedClaim !== null) {
                    $claims[] = $normalizedClaim;
                }
            }
        }

        /**
         * Backward compatibility:
         * If Groq returns the old format {"claims": []}, DeFake still works.
         */
        if (empty($claims)) {
            $legacyClaims = $result['claims'] ?? [];

            if (is_array($legacyClaims)) {
                foreach ($legacyClaims as $legacyClaim) {
                    $normalizedClaim = $this->normalizeAiClaim($legacyClaim);

                    if ($normalizedClaim !== null) {
                        $claims[] = $normalizedClaim;
                    }
                }
            }
        }

        return array_values(array_unique($claims));
    }

    private function normalizeAiClaim(mixed $claim): ?string
    {
        if (is_array($claim)) {
            $claim = $claim['claim']
                ?? $claim['original_text']
                ?? $claim['text']
                ?? null;
        }

        if (!is_scalar($claim)) {
            return null;
        }

        $claim = trim((string) $claim);

        if ($claim === '') {
            return null;
        }

        if (mb_strtoupper($claim) === self::NO_VERIFIABLE_CLAIM) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $claim) ?? $claim;
    }

    private function prepareClaimExtractionText(string $postText): string
    {
        $postText = trim($postText);

        if ($postText === '') {
            return '';
        }

        $normalizedText = preg_replace('/\R+/u', "\n", $postText) ?? $postText;
        $lines = preg_split('/\R/u', $normalizedText) ?: [$normalizedText];

        $keptLines = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            $line = preg_replace('/[*_`#]+/u', ' ', $line) ?? $line;
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($this->isLowValueSocialLine($line)) {
                continue;
            }

            $keptLines[] = $line;
        }

        $preparedText = trim(implode("\n", $keptLines));

        if ($preparedText === '') {
            return $postText;
        }

        return $preparedText;
    }

    private function isLowValueSocialLine(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return true;
        }

        if (preg_match('/^(https?:\/\/\S+|www\.\S+)$/iu', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^[@#][\p{L}\p{N}_\-.]+$/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^(share|shared|follow|like|comment|subscribe|تابعونا|شارك|مشاركة|اعجاب|لايك)$/iu', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    private function cleanExtractedClaims(array $claims, string $postText): array
    {
        $claims = array_values(array_filter(array_map(
            fn ($claim) => $this->normalizeAiClaim($claim),
            $claims
        )));

        if (empty($claims)) {
            return [];
        }

        $claims = $this->uniqueClaims($claims);

        $postTerms = $this->extractImportantTerms($postText);
        $filteredClaims = [];

        foreach ($claims as $claim) {
            if (!$this->claimHasReasonableAnchorInPost($claim, $postTerms)) {
                continue;
            }

            if ($this->claimIntroducesCriticalAnchor($claim, $postText)) {
                continue;
            }

            if ($this->looksLikePureOpinionClaim($claim)) {
                continue;
            }

            $filteredClaims[] = $claim;
        }

        if (empty($filteredClaims)) {
            return [];
        }

        $claims = $filteredClaims;

        if (count($claims) <= 1) {
            return $claims;
        }

        $claims = $this->removeSupportingDetailClaims($claims);

        if (count($claims) <= 1) {
            return $claims;
        }

        /**
         * If the AI returned main_claim + secondary_claims but they are really one story,
         * keep only the main claim. Since claimsFromAiResult() puts main_claim first,
         * this is safer than verifying repeated details.
         */
        if ($this->claimsLookLikeSameStory($claims)) {
            return [$this->selectMainClaim($claims, $postText)];
        }

        return array_slice($claims, 0, self::MAX_RETURNED_CLAIMS);
    }

    private function uniqueClaims(array $claims): array
    {
        $unique = [];
        $seen = [];

        foreach ($claims as $claim) {
            $key = implode('|', $this->extractImportantTerms($claim));

            if ($key === '') {
                $key = $this->normalizeTerm($claim);
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $claim;
        }

        return $unique;
    }

    private function claimHasReasonableAnchorInPost(string $claim, array $postTerms): bool
    {
        $claimTerms = $this->extractImportantTerms($claim);

        if (empty($claimTerms)) {
            return false;
        }

        if (empty($postTerms)) {
            return true;
        }

        $missingTerms = array_diff($claimTerms, $postTerms);

        /**
         * Reject claims that add too many important words not found in the original post.
         * This protects against AI adding names, clubs, places, dates, or facts from outside knowledge.
         */
        if (count($claimTerms) >= 3 && count($missingTerms) > max(2, (int) floor(count($claimTerms) * 0.4))) {
            return false;
        }

        return true;
    }

    private function claimIntroducesCriticalAnchor(string $claim, string $postText): bool
    {
        if ($this->introducesMissingAnchor($this->extractNumberAnchors($claim), $this->extractNumberAnchors($postText))) {
            return true;
        }

        if ($this->introducesMissingAnchor($this->extractShortUppercaseAnchors($claim), $this->extractShortAcronymComparisonAnchors($postText))) {
            return true;
        }

        if ($this->introducesMissingAnchor($this->extractCriticalMeaningAnchors($claim), $this->extractCriticalMeaningAnchors($postText))) {
            return true;
        }

        return false;
    }

    private function introducesMissingAnchor(array $claimAnchors, array $postAnchors): bool
    {
        if (empty($claimAnchors)) {
            return false;
        }

        return array_diff($claimAnchors, $postAnchors) !== [];
    }

    private function extractNumberAnchors(string $text): array
    {
        if (preg_match_all('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?\s*%?/u', $text, $matches) !== 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $anchor) => str_replace(' ', '', mb_strtolower($anchor)),
            $matches[0]
        )));
    }

    private function extractShortUppercaseAnchors(string $text): array
    {
        $matchCount = preg_match_all('/(?<![\p{L}\p{N}])[A-Z]{2,5}(?![\p{L}\p{N}])/u', $text, $matches);

        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $anchor) => mb_strtoupper($anchor),
            $matches[0]
        )));
    }

    private function extractShortAcronymComparisonAnchors(string $text): array
    {
        $matchCount = preg_match_all('/(?<![\p{L}\p{N}])[A-Za-z]{2,5}(?![\p{L}\p{N}])/u', $text, $matches);

        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $anchor) => mb_strtoupper($anchor),
            $matches[0]
        )));
    }

    private function extractCriticalMeaningAnchors(string $text): array
    {
        $anchors = [];
        $normalized = mb_strtolower($text);

        $patterns = [
            'denial' => '/\b(denied|denies|deny|denial)\b/u',
            'announcement' => '/\b(announced|announces|announce|announcement)\b/u',
            'finalized_signing' => '/\b(signed|signs)\b/u',
            'near_signing' => '/\b(close to signing|near signing|set to sign|expected to sign|in talks|negotiating)\b/u',
        ];

        foreach ($patterns as $anchor => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    private function removeSupportingDetailClaims(array $claims): array
    {
        $mainClaims = array_values(array_filter(
            $claims,
            fn (string $claim) => !$this->looksLikeSupportingDetail($claim)
        ));

        /**
         * Safety:
         * If every claim looks like a supporting detail, keep original claims.
         * Example: a post only says negotiations are advanced. That may still be the main claim.
         */
        if (empty($mainClaims)) {
            return $claims;
        }

        return $mainClaims;
    }

    private function looksLikeSupportingDetail(string $claim): bool
    {
        if (preg_match(
            '/(details?|final stages?|advanced stages?|remaining details?|agreement details?|required documents?|documents required|included documents?|includes?|including|contains?|requirements?|conditions?|supporting documents?|negotiations?|waiting for confirmation|coming days|next days|soon|تفاصيل|المعطيات|الوثائق المطلوبة|الوثائق|المستندات المطلوبة|المستندات|الأوراق المطلوبة|تشمل الوثائق|تشمل المستندات|تشمل|تتضمن|يضم|تضم|تحتوي|الشروط|المطالب|المتطلبات|المفاوضات|مفاوضات|تفاوض|تعطل المفاوضات|الأيام القادمة|الايام القادمة|خلال الأيام|خلال الايام|في انتظار|انتظار|التأكيد|التاكيد|الاتفاق النهائي|اتفاق نهائي|بخصوص تجديد|تجديد عقده|من أجل ضمان|لضمان استمراره|قريبا|قريبًا)/iu',
            $claim
        ) === 1) {
            return true;
        }

        $terms = $this->extractImportantTerms($claim);

        if (
            count($terms) <= 4
            && preg_match('/(details?|تفاصيل|تأكيد|اتفاق|agreement|confirmation|soon|قريبا|قريبًا)/iu', $claim) === 1
        ) {
            return true;
        }

        return false;
    }

    private function looksLikePureOpinionClaim(string $claim): bool
    {
        if ($this->containsStrongFactVerb($claim)) {
            return false;
        }

        if ($this->containsCheckableDetail($claim)) {
            return false;
        }

        return preg_match(
            '/(useless|terrible|bad|great|amazing|corrupt|shame|خايب|كارثة|فضيحة|فاسد|فاسدين|طحين|طحانة|بارازيت|منحل|خونة|عار|مهازل|مهزلة)/iu',
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

            if (count($terms) < 2) {
                return false;
            }

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

        foreach ($claims as $index => $claim) {
            $claimTerms = $this->extractImportantTerms($claim);
            $missingTerms = array_diff($claimTerms, $postTerms);

            $score = count($claimTerms);
            $score -= count($missingTerms) * 3;

            /**
             * In the new AI format, index 0 is main_claim.
             * Give it a bonus but still allow PHP to reject a bad main claim.
             */
            if ($index === 0) {
                $score += 4;
            }

            if ($this->containsStrongFactVerb($claim)) {
                $score += 5;
            }

            if ($this->containsCheckableDetail($claim)) {
                $score += 3;
            }

            if ($this->looksLikeSupportingDetail($claim)) {
                $score -= 6;
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
            '/(announced|approved|signed|transferred|joined|launched|opened|closed|denied|confirmed|resigned|appointed|scheduled|postponed|cancelled|canceled|won|lost|increased|decreased|arrested|sentenced|published|created|removed|renewed|terminated|decided|agreed|صفقة|انتقال|تعاقد|وقع|وقّع|انضم|اقترب|حسم|اعلن|أعلن|اعلنت|أعلنت|قرر|قررت|صادق|الغى|ألغى|تاجل|تأجل|تأجيل|فاز|خسر|ارتفع|انخفض|استقال|عين|عيّن|تعيين|نشر|نشرت|تمديد|جدد|أنهى|فسخ|أوقف|ايقاف|إيقاف|حكم|سجن|اعتقل|توفي|وفاة)/iu',
            $text
        ) === 1;
    }

    private function containsCheckableDetail(string $text): bool
    {
        if (preg_match('/\d/u', $text) === 1) {
            return true;
        }

        return preg_match(
            '/(today|tomorrow|yesterday|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|may|june|july|august|september|october|november|december|contract|agreement|ministry|government|club|federation|court|company|university|hospital|match|player|coach|اليوم|غدا|غدًا|أمس|الاثنين|الثلاثاء|الأربعاء|الخميس|الجمعة|السبت|الأحد|جانفي|فيفري|مارس|أفريل|ماي|جوان|جويلية|أوت|سبتمبر|أكتوبر|نوفمبر|ديسمبر|عقد|اتفاق|وزارة|حكومة|جامعة|رابطة|محكمة|شركة|مستشفى|مباراة|لاعب|مدرب|موسم|موسمين|سنوات|دينار|دولار|يورو|%)/iu',
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
            'will', 'would', 'could', 'should', 'may', 'might', 'new', 'about',
            'according', 'source', 'sources', 'said', 'reported', 'report',

            // French
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'dans', 'sur',
            'avec', 'pour', 'par', 'et', 'ou', 'est', 'sont', 'sera', 'selon',
            'source', 'sources', 'journal', 'rapport',

            // Arabic / Maghrebi Arabic
            'من', 'الى', 'إلى', 'على', 'في', 'عن', 'مع', 'هذا', 'هذه', 'ذلك',
            'تلك', 'الذي', 'التي', 'كان', 'كانت', 'يكون', 'سوف', 'قد', 'لقد',
            'قبل', 'بعد', 'خلال', 'دون', 'غير', 'فقط', 'حسب', 'مصادر',
            'مصدر', 'قال', 'قالت', 'جريده', 'جريدة', 'صحيفه', 'صحيفة',
            'التونسيه', 'التونسية', 'الحالي', 'الحاليه', 'الحالية',
            'انه', 'أن', 'إن', 'الى', 'إلى', 'حتى', 'كما', 'لكن', 'او', 'أو',
        ];

        $normalizedStopWords = [];

        foreach ($stopWords as $stopWord) {
            $normalizedStopWords[$this->normalizeTerm($stopWord)] = true;
        }

        $terms = [];

        foreach ($words as $word) {
            $normalized = $this->normalizeTerm((string) $word);

            if ($normalized === '') {
                continue;
            }

            if (mb_strlen($normalized) < 3 && !preg_match('/^\d+$/u', $normalized)) {
                continue;
            }

            if (isset($normalizedStopWords[$normalized])) {
                continue;
            }

            $terms[] = $normalized;
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

        $content = preg_replace('/^```json\s*/i', '', $content) ?? $content;
        $content = preg_replace('/^```\s*/', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        $json = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
