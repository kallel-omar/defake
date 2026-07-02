<?php

declare(strict_types=1);

namespace App\Service;

final class NonVerifiableReasonService
{
    public const OPINION_ONLY = 'OPINION_ONLY';
    public const JOKE_OR_SARCASM = 'JOKE_OR_SARCASM';
    public const ADVERTISEMENT = 'ADVERTISEMENT';
    public const QUESTION_ONLY = 'QUESTION_ONLY';
    public const INSULT_ONLY = 'INSULT_ONLY';
    public const VAGUE_RUMOR = 'VAGUE_RUMOR';
    public const DISCUSSION_OR_REMINDER = 'DISCUSSION_OR_REMINDER';
    public const NO_SINGLE_STANDALONE_CLAIM = 'NO_SINGLE_STANDALONE_CLAIM';
    public const TOO_MANY_CLAIMS = 'TOO_MANY_CLAIMS';

    public function classify(string $text, ?string $extractedClaim = null, array $claimVerifiability = []): array
    {
        $text = trim($text);
        $normalized = $this->normalizeText($text);
        $hasFactualSignal = $this->hasFactualSignal($normalized);

        if ($text === '') {
            return $this->result(self::NO_SINGLE_STANDALONE_CLAIM);
        }

        if ($this->isQuestionOnly($text, $normalized)) {
            return $this->result(self::QUESTION_ONLY);
        }

        if ($this->isAdvertisement($normalized)) {
            return $this->result(self::ADVERTISEMENT);
        }

        if ($this->isJokeOrSarcasm($normalized)) {
            return $this->result(self::JOKE_OR_SARCASM);
        }

        if ($this->hasTooManyClaims($normalized)) {
            return $this->result(self::TOO_MANY_CLAIMS);
        }

        if ($this->hasNoSingleStandaloneClaim($normalized)) {
            return $this->result(self::NO_SINGLE_STANDALONE_CLAIM);
        }

        if ($this->isDiscussionOrReminder($normalized)) {
            return $this->result(self::DISCUSSION_OR_REMINDER);
        }

        if ($this->isVagueRumor($normalized)) {
            return $this->result(self::VAGUE_RUMOR);
        }

        if (!$hasFactualSignal && $this->isInsultOnly($normalized)) {
            return $this->result(self::INSULT_ONLY);
        }

        if (!$hasFactualSignal && $this->isOpinionOnly($normalized)) {
            return $this->result(self::OPINION_ONLY);
        }

        return $this->result($this->fallbackCode($extractedClaim, $claimVerifiability));
    }

    private function result(string $code): array
    {
        return [
            'code' => $code,
            'title' => $this->title($code),
            'summary' => $this->summary($code),
            'verificationReason' => $this->verificationReason($code),
            'explanation' => $this->explanation($code),
        ];
    }

    private function title(string $code): string
    {
        return match ($code) {
            self::OPINION_ONLY => 'Opinion, not a factual claim',
            self::JOKE_OR_SARCASM => 'Joke or sarcasm detected',
            self::ADVERTISEMENT => 'Advertisement detected',
            self::QUESTION_ONLY => 'Question detected',
            self::INSULT_ONLY => 'Insult or emotional accusation',
            self::VAGUE_RUMOR => 'Vague rumor detected',
            self::DISCUSSION_OR_REMINDER => 'Discussion or reminder detected',
            self::TOO_MANY_CLAIMS => 'Multiple claims detected',
            default => 'No single standalone claim detected',
        };
    }

    private function summary(string $code): string
    {
        return match ($code) {
            self::OPINION_ONLY => 'The text expresses a personal view or evaluation, but does not state a specific checkable event.',
            self::JOKE_OR_SARCASM => 'The text appears to be humorous or sarcastic rather than a factual claim DeFake can verify.',
            self::ADVERTISEMENT => 'The text is promotional content, not a standalone factual claim for verification.',
            self::QUESTION_ONLY => 'The text asks a question instead of making a factual assertion.',
            self::INSULT_ONLY => 'The text contains an insult or emotional accusation without a specific checkable event.',
            self::VAGUE_RUMOR => 'The text uses rumor-like wording without enough concrete details to verify safely.',
            self::DISCUSSION_OR_REMINDER => 'The text is framed as discussion, reminder, or commentary rather than one checkable claim.',
            self::TOO_MANY_CLAIMS => 'The text contains several possible claims, so DeFake needs one claim to be selected before verification.',
            default => 'The text does not contain one clear standalone factual claim that can be safely checked.',
        };
    }

    private function verificationReason(string $code): string
    {
        return match ($code) {
            self::OPINION_ONLY => 'Verification was skipped because the text is an opinion, not a factual claim.',
            self::JOKE_OR_SARCASM => 'Verification was skipped because the text appears to be joke or sarcasm.',
            self::ADVERTISEMENT => 'Verification was skipped because the text is promotional rather than a factual claim.',
            self::QUESTION_ONLY => 'Verification was skipped because the text asks a question instead of making a claim.',
            self::INSULT_ONLY => 'Verification was skipped because the text is an insult or emotional accusation without a checkable event.',
            self::VAGUE_RUMOR => 'Verification was skipped because the rumor is too vague to check safely.',
            self::DISCUSSION_OR_REMINDER => 'Verification was skipped because the text is discussion or reminder content, not one claim.',
            self::TOO_MANY_CLAIMS => 'Verification was skipped because multiple claims are present and no single claim was selected.',
            default => 'Verification was skipped because no single standalone factual claim was detected.',
        };
    }

    private function explanation(string $code): string
    {
        return match ($code) {
            self::OPINION_ONLY => 'DeFake could not verify this because it is framed as opinion rather than a standalone factual claim.',
            self::JOKE_OR_SARCASM => 'DeFake could not verify this because jokes and sarcasm do not provide a stable factual claim to check.',
            self::ADVERTISEMENT => 'DeFake could not verify this as news because it is promotional content rather than a factual public claim.',
            self::QUESTION_ONLY => 'DeFake could not verify this because questions need to be rewritten as a specific factual claim first.',
            self::INSULT_ONLY => 'DeFake could not verify this because insults or emotional accusations need a specific event, decision, or action to check.',
            self::VAGUE_RUMOR => 'DeFake could not verify this because the wording is too vague and does not include enough concrete details.',
            self::DISCUSSION_OR_REMINDER => 'DeFake could not verify this because it is framed as discussion or reminder content rather than one claim.',
            self::TOO_MANY_CLAIMS => 'DeFake detected multiple possible claims. For now, submit one standalone claim so it can be checked safely.',
            default => 'DeFake could not verify this because it does not contain one clear standalone factual claim.',
        };
    }

    private function fallbackCode(?string $extractedClaim, array $claimVerifiability): string
    {
        $claim = trim((string) $extractedClaim);

        if ($claim !== '' && mb_strtoupper($claim) !== 'NO_VERIFIABLE_CLAIM') {
            return self::NO_SINGLE_STANDALONE_CLAIM;
        }

        $missingElements = $claimVerifiability['missingElements'] ?? [];

        if (is_array($missingElements) && in_array('action', $missingElements, true)) {
            return self::NO_SINGLE_STANDALONE_CLAIM;
        }

        return self::NO_SINGLE_STANDALONE_CLAIM;
    }

    private function isQuestionOnly(string $text, string $normalized): bool
    {
        $trimmed = trim($text);

        if (preg_match('/[?؟]\s*$/u', $trimmed) === 1) {
            return true;
        }

        return preg_match(
            '/^(did|does|do|is|are|was|were|has|have|had|can|could|will|would|should|what|when|where|who|why|how)\b|^(هل|شنو|شنوة|شكون|علاش|لماذا|متى|اين|أين|كيف|كيفاش)\b/iu',
            $normalized
        ) === 1;
    }

    private function isAdvertisement(string $normalized): bool
    {
        return preg_match(
            '/\b(buy|shop|order|subscribe|sale|discount|promo|coupon|jersey now|official jersey)\b|(?:اشتري|اشري|اشتر|اطلب|تسوق|تسوّق|تخفيض|خصم|عرض خاص|قميص رسمي)/iu',
            $normalized
        ) === 1;
    }

    private function isJokeOrSarcasm(string $normalized): bool
    {
        return preg_match(
            '/\b(joke|sarcasm|sarcastic|lol|haha|so bad even|even google refuses|refuses to search)\b|(?:هههه|نكتة|نكته|سخرية|سخريه|ساخر|حتى\s+(?:google|غوغل|قوقل))/iu',
            $normalized
        ) === 1;
    }

    private function hasTooManyClaims(string $normalized): bool
    {
        if (mb_strlen($normalized) < 80) {
            return false;
        }

        return $this->factualSignalCount($normalized) >= 3;
    }

    private function hasNoSingleStandaloneClaim(string $normalized): bool
    {
        // TODO: Promote this path to MULTI_CLAIM_NEEDS_SELECTION when the UI can ask users to choose one claim.
        if (preg_match('/\b(several|multiple|various|many claims|mixes|mentions|discusses)\b|(?:عدة|عده|عديد|برشة|برشه|اكثر من|أكثر من|يخلط|بين .* و|اكثر من زاوية|اكثر من زاويه|أكثر من زاوية)/iu', $normalized) === 1) {
            return true;
        }

        if (mb_strlen($normalized) < 220) {
            return false;
        }

        return preg_match('/(?:و|and|،|,)/u', $normalized) === 1
            && preg_match('/(?:تسلل|ضربة جزاء|هدف|حكم|فار|var|referee|penalty|offside|goal)/iu', $normalized) === 1;
    }

    private function isDiscussionOrReminder(string $normalized): bool
    {
        return preg_match(
            '/\b(reminder|remember|discussion|discuss|thoughts|what do you think|for context|let us talk|let\'s talk)\b|(?:تذكير|مجرد تذكير|نقاش|للنقاش|شنوة رايكم|شنوة رأيكم|ما رايكم|ما رأيكم|رايكم|رأيكم|خلينا نحكيو)/iu',
            $normalized
        ) === 1;
    }

    private function isVagueRumor(string $normalized): bool
    {
        return preg_match(
            '/\b(rumou?r|sources?|breaking|exclusive|leak|leaked|soon|big surprise|reportedly)\b|(?:عاجل|حصري|مصادر|مصدر خاص|مصادر خاصة|مصادر خاصه|يقال|قريبا|قريباً|مفاجأة|مفاجاه|تسريب|زلزال)/iu',
            $normalized
        ) === 1;
    }

    private function isInsultOnly(string $normalized): bool
    {
        return preg_match(
            '/\b(corrupt|trash|garbage|useless|idiot|liar|traitor)\b|(?:فاسد|فاسدين|مزبلة|مزبله|زبالة|زباله|فضيحة|فضيحه|خائن|خونة|خونه|حقير|وسخ|عار)/iu',
            $normalized
        ) === 1;
    }

    private function isOpinionOnly(string $normalized): bool
    {
        return preg_match(
            '/\b(i feel|i think|in my opinion|my opinion|feels like|becoming worse|getting worse|bad|terrible|best|worst|should|must)\b|(?:رأيي|رايي|نحس|أحس|اعتقد|نعتقد|بالنسبة لي|بالنسبه لي|اسوأ|اسوا|أسوأ|افضل|أفضل|سيء|رديء|كارثة|كارثه)/iu',
            $normalized
        ) === 1;
    }

    private function hasFactualSignal(string $normalized): bool
    {
        return $this->factualSignalCount($normalized) > 0;
    }

    private function factualSignalCount(string $normalized): int
    {
        $matchCount = preg_match_all(
            '/\b(announced|confirmed|denied|decided|approved|rejected|signed|appointed|resigned|cancelled|canceled|postponed|published|issued|launched|opened|closed|increased|decreased|won|lost|died|arrested|visited|sentenced)\b|(?:اعلن|أعلن|اعلنت|أعلنت|اكد|أكد|قرر|قررت|رفض|رفضت|وقع|تعاقد|الغى|ألغى|تاجل|تأجل|فاز|خسر|توفي|اعتقل|نشر|نشرت|تحصل\s+على|احتسب|تم\s+احتساب|تم\s+الغاء|تم\s+إلغاء|ضربة\s+جزاء\s+غير\s+صحيحة|ضربه\s+جزاء\s+غير\s+صحيحه|هدف\s+مسبوق\s+بتسلل)/iu',
            $normalized
        );

        return $matchCount === false ? 0 : $matchCount;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
