<?php

declare(strict_types=1);

namespace App\Service;

class ClaimExtractionService
{
    private const NO_VERIFIABLE_CLAIM = 'NO_VERIFIABLE_CLAIM';
    private const MAX_AI_POST_CHARS = 10000;
    private const MAX_RETURNED_CLAIMS = 2;
    private const MIN_CLAIM_LENGTH = 15;

    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
    }

    public function extract(string $postText, array $sourceContext = []): array
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

        $prompt = $this->buildPrompt();

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

        $factCheckable = (bool) ($result['fact_checkable'] ?? false);

        if (!$factCheckable) {
            $fallbackClaims = $this->phpFallbackExtract($postText, $sourceContext);
            if (!empty($fallbackClaims)) {
                return array_slice($fallbackClaims, 0, self::MAX_RETURNED_CLAIMS);
            }

            return [self::NO_VERIFIABLE_CLAIM];
        }
$claims = $this->claimsFromAiResult($result);

if (empty($claims)) {
    return $this->fallbackOrNoVerifiable($postText, $sourceContext);
}

// === CRITICAL: Pass sourceContext to AI cleanup too ===
$claims = $this->cleanExtractedClaims($claims, $postText, $sourceContext);

if (empty($claims)) {
    return $this->fallbackOrNoVerifiable($postText, $sourceContext);
}

return array_slice($claims, 0, self::MAX_RETURNED_CLAIMS);
    }

    // ==================== AI PROMPT (complete) ====================
private function fallbackOrNoVerifiable(string $postText, array $sourceContext = []): array
{
    $fallbackClaims = $this->phpFallbackExtract($postText, $sourceContext);

    if (!empty($fallbackClaims)) {
        return array_slice($fallbackClaims, 0, self::MAX_RETURNED_CLAIMS);
    }

    return [self::NO_VERIFIABLE_CLAIM];
}
    private function buildPrompt(): string
    {
        return <<<PROMPT
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
    }

    // ==================== PHP FALLBACK (AI said no claim) ====================

    private function phpFallbackExtract(string $postText, array $sourceContext = []): array
    {
        $sentences = $this->splitIntoSentences($postText);
        $candidates = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < self::MIN_CLAIM_LENGTH) {
                continue;
            }

            $subject = $this->extractSubject($sentence);
            if ($subject === null) {
                continue;
            }

            $action = $this->extractFactualAction($sentence);
            if ($action === null) {
                continue;
            }

            if (!$this->containsCheckableDetail($sentence)) {
                continue;
            }

            if ($this->sentenceIsPureOpinion($sentence)) {
                continue;
            }

            if ($this->isVagueSubject($subject, $sentence, $sourceContext)) {
                continue;
            }

            $claim = $this->buildClaimFromSentence($sentence, $subject, $action);
            if ($claim === null) {
                continue;
            }

            $candidates[] = $claim;
        }

        if (empty($candidates)) {
            return [];
        }

        $best = $this->scoreFallbackCandidates($candidates, $postText);

        return $best ? [$best] : [];
    }

    private function splitIntoSentences(string $text): array
    {
        $text = preg_replace('/\R+/u', ' ', $text);
        $pattern = '/(?<=[.!?؟。．!！?？])\s+/u';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts) {
            return [$text];
        }

        return array_values(array_filter(array_map('trim', $parts)));
    }

    private function extractSubject(string $sentence): ?string
    {
        if (preg_match('/^(ال[\p{L}\p{N}_\-.]+)/u', $sentence, $m)) {
            $candidate = $m[1];
            if ($this->isValidSubject($candidate)) {
                return $candidate;
            }
        }

        if (preg_match('/^([A-Z][\p{L}\p{N}_\-.]*(?:\s+(?:de|del|van|von|bin|al-|el-)?[A-Z][\p{L}\p{N}_\-.]*)*)/u', $sentence, $m)) {
            $candidate = trim($m[1]);
            if ($this->isValidSubject($candidate) && mb_strlen($candidate) > 2) {
                return $candidate;
            }
        }

        $invertedPattern = '/(?:أعلن|أعلنت|قال|قالت|صرح|صرحت|أكد|أكدت|أفاد|أفادت|announced|said|stated|confirmed|reported)\s+(?:أن\s+)?(ال[\p{L}\p{N}_\-.]+|[A-Z][\p{L}\p{N}_\-.]*)/iu';
        if (preg_match($invertedPattern, $sentence, $m)) {
            $candidate = $m[1];
            if ($this->isValidSubject($candidate)) {
                return $candidate;
            }
        }

        if (preg_match('/(ال[\p{L}\p{N}_\-.]{3,}|[A-Z][\p{L}\p{N}_\-.]{2,}(?:\s+[A-Z][\p{L}\p{N}_\-.]{2,}){0,2})/u', $sentence, $m)) {
            $candidate = $m[1];
            if ($this->isValidSubject($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isValidSubject(string $candidate): bool
    {
        $normalized = $this->normalizeTerm($candidate);

        $invalidSubjects = [
            'الله', 'الذي', 'التي', 'الذين', 'اللواتي', 'اللاتي', 'الان', 'الآن',
            'اليوم', 'غدا', 'أمس', 'هنا', 'هناك', 'كل', 'بعض', 'هذا', 'هذه', 'ذلك',
            'التونسية', 'التونسيه', 'العربية', 'العربيه', 'العالمية', 'العالميه',
            'the', 'this', 'that', 'these', 'those', 'there', 'here', 'today',
            'tomorrow', 'yesterday', 'every', 'some', 'all', 'many', 'most',
            'le', 'la', 'les', 'ce', 'cet', 'cette', 'ces', 'il', 'elle', 'on',
        ];

        if (in_array($normalized, $invalidSubjects, true)) {
            return false;
        }

        if (mb_strlen($normalized) < 3 && !preg_match('/^[A-Z]{2,}$/u', $candidate)) {
            return false;
        }

        return true;
    }

       /**
     * Rejects vague subjects unless sentence or sourceContext provides specificity.
     * Uses DeFake pipeline field names: pageName, userName, userId, postUrl, source_type
     *
     * CRITICAL: Only real source/entity contexts may save a vague subject.
     * Manual entries, CLI tests, admin text, or generic users must NOT save vague subjects.
     */
    private function isVagueSubject(string $subject, string $sentence, array $sourceContext): bool
    {
        $vaguePatterns = [
            '/^السفارة?$/u', '/^السفاره?$/u',
            '/^الوزارة?$/u', '/^الوزاره?$/u',
            '/^النادي$/u', '/^الفريق$/u', '/^المستشفى$/u',
            '/^المحكمة?$/u', '/^المحكمه?$/u',
            '/^الشركة?$/u', '/^الشركه?$/u',
            '/^الجامعة?$/u', '/^الجامعه?$/u',
            '/^الرابطة$/u', '/^الاتحاد$/u',
            '/^الحكومة?$/u', '/^الحكومه?$/u',
            '/^البلدية?$/u', '/^البلديه?$/u',
            '/^الولاية?$/u', '/^الولايه?$/u',
            '/^المديرية?$/u', '/^المديريه?$/u',
            '/^المصنع$/u', '/^المطار$/u', '/^الميناء$/u',
            '/^المركز$/u', '/^المعهد$/u',
            '/^المؤسسة?$/u', '/^المؤسسه?$/u',
            '/^الهيئة?$/u', '/^الهيئه?$/u',
            '/^المنظمة?$/u', '/^المنظمه?$/u',
            '/^الجمعية?$/u', '/^الجمعيه?$/u',
            '/^اللجنة?$/u', '/^اللجنه?$/u',
            '/^المجلس$/u', '/^الديوان$/u',
            '/^القنصلية?$/u', '/^القنصليه?$/u',
            '/^السفير$/u', '/^الوزير$/u', '/^المدير$/u',
            '/^الرئيس$/u', '/^الملك$/u', '/^الامير$/u',
            '/^الحاكم$/u', '/^النائب$/u', '/^القاضي$/u',
            '/^المحامي$/u', '/^الطبيب$/u', '/^الممرض$/u',
            '/^المدرب$/u', '/^اللاعب$/u', '/^الفنان$/u',
            '/^المغني$/u', '/^الممثل$/u', '/^المؤلف$/u',
            '/^الكاتب$/u', '/^الصحفي$/u', '/^الخبير$/u',
            '/^المختص$/u', '/^الطالب$/u', '/^التلميذ$/u',
            '/^الموظف$/u', '/^العامل$/u', '/^المواطن$/u',
            '/^الشخص$/u', '/^الرجل$/u',
            '/^المرأة?$/u', '/^المرأه?$/u',
            '/^الطفل$/u', '/^الشاب$/u', '/^الفتاة$/u',
            '/^embassy$/iu', '/^ministry$/iu', '/^hospital$/iu',
            '/^court$/iu', '/^company$/iu', '/^university$/iu',
            '/^federation$/iu', '/^government$/iu', '/^municipality$/iu',
            '/^factory$/iu', '/^airport$/iu', '/^port$/iu',
            '/^center$/iu', '/^institute$/iu', '/^organization$/iu',
            '/^association$/iu', '/^committee$/iu', '/^council$/iu',
            '/^consulate$/iu', '/^ambassador$/iu', '/^minister$/iu',
            '/^director$/iu', '/^president$/iu', '/^king$/iu',
            '/^prince$/iu', '/^governor$/iu', '/^deputy$/iu',
            '/^judge$/iu', '/^lawyer$/iu', '/^doctor$/iu',
            '/^nurse$/iu', '/^coach$/iu', '/^player$/iu',
            '/^artist$/iu', '/^singer$/iu', '/^actor$/iu',
            '/^author$/iu', '/^writer$/iu', '/^journalist$/iu',
            '/^expert$/iu', '/^specialist$/iu', '/^student$/iu',
            '/^pupil$/iu', '/^employee$/iu', '/^worker$/iu',
            '/^citizen$/iu', '/^person$/iu', '/^man$/iu',
            '/^woman$/iu', '/^child$/iu', '/^youth$/iu', '/^girl$/iu',
        ];

        $isVague = false;
        foreach ($vaguePatterns as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                $isVague = true;
                break;
            }
        }

        if (!$isVague) {
            return false;
        }

        // Check 1: Does the sentence itself provide specificity IMMEDIATELY after the subject?
        // Only check the first few words after the subject, not the entire sentence.
        // Qualifiers like country, city, or proper noun must come right after the subject.
        $subjectPos = mb_stripos($sentence, $subject);
        if ($subjectPos !== false) {
            $afterSubject = mb_substr($sentence, $subjectPos + mb_strlen($subject));
            // Only look at first ~40 chars (roughly 4-5 words) after subject
            $afterSubjectWindow = mb_substr($afterSubject, 0, 40);

            // Pattern: "السفارة السويدية في تونس" → " السويدية في تونس" qualifies
            // Pattern: "السفارة أعلنت أنها..." → " أعلنت أنها..." does NOT qualify
            if (preg_match('/^\s+(?:ال[\p{L}\p{N}]{2,}(?:\s+في\s+[\p{L}\p{N}]{2,})?|[A-Z][\p{L}\p{N}]{1,}(?:\s+[A-Z][\p{L}\p{N}]{1,})?)/u', $afterSubjectWindow)) {
                return false;
            }
        }

        // Check 2: Does sourceContext identify the source?
        // Only real source/entity contexts may save a vague subject.
        // Manual entries, CLI tests, admin text, or generic users must NOT save vague subjects.
        $sourceType = (string) ($sourceContext['source_type'] ?? '');

        $contextCanIdentifySubject = in_array($sourceType, [
            'facebook_post',
            'facebook_page',
            'official_page',
            'official_website',
            'public_source',
        ], true);

        if ($contextCanIdentifySubject) {
            $explicitName = $sourceContext['pageName']
                ?? $sourceContext['sourceName']
                ?? $sourceContext['organizationName']
                ?? null;

            if ($explicitName !== null && mb_strlen(trim((string) $explicitName)) > 2) {
                return false;
            }
        }

        // Subject is vague and no context saves it
        return true;
    }
   
    private function extractFactualAction(string $sentence): ?string
    {
        $actions = [
            'أعلن', 'أعلنت', 'أعلنا', 'أعلنوا', 'أعلنّ',
            'قرر', 'قررت', 'قرروا', 'قررن', 'قررتم',
            'وقع', 'وقّع', 'وقعت', 'وقعوا', 'وقّعت',
            'انضم', 'انضمت', 'انضموا', 'انضمّ', 'انضممت',
            'استقال', 'استقالت', 'استقالوا', 'استقالا',
            'عين', 'عيّن', 'عينت', 'عيّنت', 'عينوا', 'عيّنوا',
            'ألغى', 'ألغت', 'ألغوا', 'ألغينا',
            'أوقف', 'أوقفت', 'أوقفوا', 'أوقفنا', 'إيقاف',
            'اعتقل', 'اعتقلت', 'اعتقلوا',
            'حكم', 'حكمت', 'حكموا',
            'نشر', 'نشرت', 'نشروا',
            'أكد', 'أكدت', 'أكدوا', 'أكدنا',
            'نفى', 'نفت', 'نفوا', 'نفينا',
            'رفض', 'رفضت', 'رفضوا',
            'وافق', 'وافقت', 'وافقوا',
            'صادق', 'صادقت', 'صادقوا',
            'أقر', 'أقرت', 'أقروا',
            'أصدر', 'أصدرت', 'أصدروا',
            'أنهى', 'أنهت', 'أنهوا',
            'فسخ', 'فسخت', 'فسخوا',
            'جدد', 'جددت', 'جددوا',
            'تمديد', 'تجديد',
            'فاز', 'فازت', 'فازوا',
            'خسر', 'خسرت', 'خسروا',
            'ارتفع', 'ارتفعت', 'ارتفعوا',
            'انخفض', 'انخفضت', 'انخفضوا',
            'توفي', 'توفيت', 'توفوا', 'وفاة',
            'انتقل', 'انتقلت', 'انتقلوا',
            'تعاقد', 'تعاقدت', 'تعاقدوا',
            'اقترب', 'اقتربت', 'اقتربوا',
            'حسم', 'حسمت', 'حسموا',
            'تأجل', 'تأجلت', 'تأجلوا', 'تأجيل',
            'أظهر', 'أظهرت', 'أظهروا',
            'اكتشف', 'اكتشفت', 'اكتشفوا',
            'أثبت', 'أثبتت', 'أثبتوا',
            'بين', 'بينت', 'بينوا',
            'أشار', 'أشارت', 'أشاروا',
            'أفاد', 'أفادت', 'أفادوا',
            'ذكر', 'ذكرت', 'ذكروا',
            'أوضح', 'أوضحت', 'أوضحوا',
            'announced', 'approved', 'signed', 'transferred', 'joined', 'launched',
            'opened', 'closed', 'denied', 'confirmed', 'resigned', 'appointed',
            'scheduled', 'postponed', 'cancelled', 'canceled', 'won', 'lost',
            'increased', 'decreased', 'arrested', 'sentenced', 'published',
            'created', 'removed', 'renewed', 'terminated', 'decided', 'agreed',
            'found', 'discovered', 'showed', 'revealed', 'reported', 'claimed',
            'stated', 'confirmed', 'rejected', 'approved', 'vetoed', 'passed',
            'introduced', 'implemented', 'suspended', 'banned', 'lifted', 'imposed',
            'declared', 'established', 'dissolved', 'merged', 'acquired', 'sold',
            'bought', 'invested', 'funded', 'granted', 'awarded', 'nominated',
            'elected', 'defeated', 'resigned', 'retired', 'died', 'injured',
            'hospitalized', 'released', 'detained', 'charged', 'acquitted',
            'convicted', 'pardoned', 'expelled', 'suspended', 'fined', 'sanctioned',
        ];
foreach ($actions as $action) {
    $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($action, '/') . '(?![\p{L}\p{N}_])/iu';

    if (preg_match($pattern, $sentence) === 1) {
        return $action;
    }
}
        

        return null;
    }

    private function sentenceIsPureOpinion(string $sentence): bool
    {
        $opinionPatterns = [
            '/\b(useless|terrible|bad|great|amazing|corrupt|shame|best|worst|beautiful|ugly|stupid|idiot|liar|pathetic|disgusting)\b/iu',
            '/\b(خايب|كارثة|فضيحة|فاسد|فاسدين|طحين|طحانة|بارازيت|منحل|خونة|عار|مهازل|مهزلة|غبي|حمق|كذاب|حقير|مقرف)\b/iu',
            '/\b(I think|I believe|In my opinion|IMO|personally|I feel|I guess|I suppose)\b/iu',
            '/\b(أعتقد|أظن|برأيي|في رأيي|أحس|أشعر|على ما أعتقد)\b/iu',
            '/\b(à mon avis|je pense|je crois|selon moi|personnellement)\b/iu',
        ];

        $hasOpinionMarker = false;
        foreach ($opinionPatterns as $pattern) {
            if (preg_match($pattern, $sentence) === 1) {
                $hasOpinionMarker = true;
                break;
            }
        }

        if ($hasOpinionMarker) {
            $hasStrongFact = $this->containsStrongFactVerb($sentence);
            $hasDetail = $this->containsCheckableDetail($sentence);

            if ($hasStrongFact && $hasDetail) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function buildClaimFromSentence(string $sentence, string $subject, string $action): ?string
    {
        $subjectPos = mb_stripos($sentence, $subject);
        if ($subjectPos === false) {
            $words = preg_split('/\s+/u', $sentence);
            foreach ($words as $word) {
                if ($this->normalizeTerm($word) === $this->normalizeTerm($subject)) {
                    $subjectPos = mb_strpos($sentence, $word);
                    $subject = $word;
                    break;
                }
            }
        }

        if ($subjectPos === false) {
            return null;
        }

        $claimStart = $subjectPos;
        $claimText = mb_substr($sentence, $claimStart);

        if (mb_strlen($claimText) > 200) {
            $cutPoint = mb_strpos($claimText, '.', 180);
            if ($cutPoint === false) {
                $cutPoint = mb_strpos($claimText, '،', 180);
            }
            if ($cutPoint === false) {
                $cutPoint = mb_strpos($claimText, ',', 180);
            }
            if ($cutPoint !== false && $cutPoint > 50) {
                $claimText = mb_substr($claimText, 0, $cutPoint + 1);
            }
        }

        $claimText = trim($claimText);

        if (mb_strlen($claimText) < self::MIN_CLAIM_LENGTH) {
            return null;
        }

        $claimText = preg_replace('/\s+/u', ' ', $claimText);

        return $claimText;
    }

    private function scoreFallbackCandidates(array $candidates, string $originalText): ?string
    {
        if (empty($candidates)) {
            return null;
        }

        $postTerms = $this->extractImportantTerms($originalText);
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $candidate) {
            $score = 0;

            $claimTerms = $this->extractImportantTerms($candidate);
            $missing = array_diff($claimTerms, $postTerms);

            $score += count($claimTerms) * 2;
            $score -= count($missing) * 4;

            if ($this->containsCheckableDetail($candidate)) {
                $score += 5;
            }

            if ($this->containsStrongFactVerb($candidate)) {
                $score += 3;
            }

            $len = mb_strlen($candidate);
            if ($len < 30) {
                $score -= 3;
            }
            if ($len > 300) {
                $score -= 2;
            }

            if (preg_match('/^(ال[A-Z])/iu', $candidate)) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($bestScore < 0) {
            return null;
        }

        return $best;
    }

    // ==================== AI CLAIM CLEANUP (with sourceContext) ====================

    private function cleanExtractedClaims(array $claims, string $postText, array $sourceContext = []): array
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

            if ($this->looksLikePureOpinionClaim($claim)) {
                continue;
            }

            $claimSubject = $this->extractSubjectFromClaim($claim);
            if ($claimSubject !== null && $this->isVagueSubject($claimSubject, $claim, $sourceContext)) {
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

        if ($this->claimsLookLikeSameStory($claims)) {
            return [$this->selectMainClaim($claims, $postText)];
        }

        return array_slice($claims, 0, self::MAX_RETURNED_CLAIMS);
    }

    private function extractSubjectFromClaim(string $claim): ?string
    {
        if (preg_match('/^(ال[\p{L}\p{N}_\-.]{2,})/u', $claim, $m)) {
            return $m[1];
        }

        if (preg_match('/^([A-Z][\p{L}\p{N}_\-.]{2,}(?:\s+[A-Z][\p{L}\p{N}_\-.]{2,}){0,2})/u', $claim, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // ==================== EXISTING METHODS (unchanged) ====================

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

        if (count($claimTerms) >= 3 && count($missingTerms) > max(2, (int) floor(count($claimTerms) * 0.4))) {
            return false;
        }

        return true;
    }

    private function removeSupportingDetailClaims(array $claims): array
    {
        $mainClaims = array_values(array_filter(
            $claims,
            fn (string $claim) => !$this->looksLikeSupportingDetail($claim)
        ));

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
            'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with',
            'from', 'that', 'this', 'these', 'those', 'and', 'or', 'but', 'is',
            'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
            'will', 'would', 'could', 'should', 'may', 'might', 'new', 'about',
            'according', 'source', 'sources', 'said', 'reported', 'report',
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'dans', 'sur',
            'avec', 'pour', 'par', 'et', 'ou', 'est', 'sont', 'sera', 'selon',
            'source', 'sources', 'journal', 'rapport',
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