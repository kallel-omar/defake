<?php

namespace App\Service;

class ClaimVerifiabilityService
{
    public function assess(string $claim, string $postText = ''): array
    {
        $claim = trim($claim);

        if ($claim === '' || $claim === 'NO_VERIFIABLE_CLAIM') {
            return $this->notVerifiable(
                'No extracted claim was available.',
                ['claim'],
                'unknown'
            );
        }

        $normalized = $this->normalizeText($claim);

        $specificTerms = $this->specificTerms($normalized);
        $specificTermsCount = count($specificTerms);

        $hasStrongAction = $this->containsAny($normalized, $this->strongFactualActions());
        $hasSoftAction = $this->containsAny($normalized, $this->softFactualActions());
        $hasCheckableDetail = $this->hasCheckableDetail($normalized);
        $vaguenessLevel = $this->detectVaguenessLevel($normalized);

        $subjectPresent = $specificTermsCount >= 1;
        $actionPresent = $hasStrongAction || $hasSoftAction;

        $claimType = $this->detectClaimType($normalized);

        if (!$subjectPresent) {
            return $this->notVerifiable(
                'The claim does not contain an identifiable subject.',
                ['subject'],
                $claimType
            );
        }

        if (!$actionPresent) {
            return $this->notVerifiable(
                'The claim does not contain a concrete factual action or assertion.',
                ['action'],
                $claimType
            );
        }

        if ($vaguenessLevel === 'high' && !$hasCheckableDetail && $specificTermsCount < 3) {
            return $this->notVerifiable(
                'The claim uses vague or speculative wording without enough concrete details.',
                ['specific subject', 'specific object/event', 'date/source/context'],
                $claimType
            );
        }

        if ($hasSoftAction && !$hasCheckableDetail && $specificTermsCount < 3) {
            return $this->notVerifiable(
                'The claim is only a possibility, rumor, or future expectation without enough concrete details.',
                ['specific object/event', 'source/date/context'],
                $claimType
            );
        }

        if (!$hasCheckableDetail && $specificTermsCount < 2) {
            return $this->notVerifiable(
                'The claim lacks enough checkable context to verify safely.',
                ['object/event/date/source/context'],
                $claimType
            );
        }

        return [
            'verifiable' => true,
            'reason' => 'The claim contains an identifiable subject, a factual action, and enough checkable context.',
            'missingElements' => [],
            'claimType' => $claimType,
            'subjectPresent' => $subjectPresent,
            'actionPresent' => true,
            'checkableDetailPresent' => $hasCheckableDetail,
            'vaguenessLevel' => $vaguenessLevel,
            'signals' => [
                'specificTermsCount' => $specificTermsCount,
                'hasStrongAction' => $hasStrongAction,
                'hasSoftAction' => $hasSoftAction,
                'hasCheckableDetail' => $hasCheckableDetail,
                'specificTerms' => $specificTerms,
            ],
        ];
    }

    private function notVerifiable(string $reason, array $missingElements, string $claimType): array
    {
        return [
            'verifiable' => false,
            'reason' => $reason,
            'missingElements' => $missingElements,
            'claimType' => $claimType,
            'subjectPresent' => !in_array('subject', $missingElements, true),
            'actionPresent' => !in_array('action', $missingElements, true),
            'checkableDetailPresent' => false,
            'vaguenessLevel' => 'high',
            'signals' => [],
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Arabic normalization
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;

        return trim($text);
    }

    private function containsAny(string $text, array $signals): bool
    {
        foreach ($signals as $signal) {
            if (str_contains($text, $this->normalizeText($signal))) {
                return true;
            }
        }

        return false;
    }

    private function specificTerms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}.%]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (!$words) {
            return [];
        }

        $weakTerms = array_map(
            fn (string $term) => $this->normalizeText($term),
            [
                // Generic vague Arabic / Maghrebi / social media words
                'خبر', 'حصري', 'عاجل', 'مصادر', 'مصدر', 'خاص', 'خاصة',
                'تسريب', 'قريبا', 'قريباً', 'قريب', 'قادم', 'قادمة',
                'مفاجأة', 'مفاجاه', 'كبيرة', 'كبير', 'مدوية', 'مدويه',
                'الساعات', 'القادمة', 'القادمه', 'خلال', 'حقيقة', 'الحقيقة',
                'فضيحة', 'كارثة', 'صدمة', 'زلزال', 'سيحدث', 'يحدث',

                // Generic vague English/French words
                'breaking', 'exclusive', 'sources', 'source', 'rumor', 'rumour',
                'soon', 'big', 'surprise', 'huge', 'shocking',
                'urgent', 'leak', 'leaked', 'truth',
                'rumeur', 'source', 'sources', 'bientot', 'bientôt',
                'surprise', 'urgent', 'exclusif',
            ]
        );

        $terms = [];

        foreach ($words as $word) {
            $term = $this->normalizeText($word);

            if (mb_strlen($term) < 3) {
                continue;
            }

            if (in_array($term, $weakTerms, true)) {
                continue;
            }

            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function hasCheckableDetail(string $text): bool
    {
        if (preg_match('/\d/u', $text) === 1) {
            return true;
        }

        if (preg_match('/https?:\/\/|www\./i', $text) === 1) {
            return true;
        }

        return $this->containsAny($text, [
            // Dates / time / periods
            'اليوم', 'امس', 'أمس', 'غدا', 'غداً', 'الاثنين', 'الثلاثاء', 'الاربعاء',
            'الخميس', 'الجمعه', 'الجمعة', 'السبت', 'الاحد', 'الأحد',
            'يناير', 'فيفري', 'فبراير', 'مارس', 'افريل', 'أفريل', 'ابريل', 'أبريل',
            'ماي', 'يونيو', 'جوان', 'يوليو', 'جويلية', 'اوت', 'أوت', 'سبتمبر',
            'اكتوبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
            'today', 'yesterday', 'tomorrow', 'monday', 'tuesday', 'wednesday',
            'thursday', 'friday', 'saturday', 'sunday',
            'january', 'february', 'march', 'april', 'may', 'june', 'july',
            'august', 'september', 'october', 'november', 'december',
            'aujourd', 'hier', 'demain', 'lundi', 'mardi', 'mercredi', 'jeudi',
            'vendredi', 'samedi', 'dimanche',
            // Durations / periods / contract terms
            'موسم', 'موسمين', 'مواسم', 'سنة', 'سنتين', 'سنوات', 'عام', 'عامين', 'اعوام',
            'شهر', 'شهرين', 'اشهر', 'أشهر', 'اسبوع', 'أسبوع', 'اسبوعين', 'أسبوعين',
            'لمدة', 'مده', 'مدة',

            'season', 'seasons', 'year', 'years', 'month', 'months', 'week', 'weeks',
            'two-year', 'three-year', 'one-year', 'multi-year', 'duration', 'period',

            'saison', 'saisons', 'an', 'ans', 'année', 'années', 'mois', 'semaine', 'semaines',
            'durée', 'periode', 'période',

            // Public checkable anchors
            'قرار', 'بلاغ', 'بيان', 'وثيقه', 'وثيقة', 'قانون', 'حكم', 'محكمه', 'محكمة',
            'اتفاق', 'عقد', 'تقرير', 'نتيجه', 'نتيجة', 'سعر', 'نسبه', 'نسبة',
            'statement', 'decision', 'document', 'law', 'court', 'agreement',
            'contract', 'report', 'result', 'price', 'percentage',
            'communique', 'communiqué', 'decision', 'décision', 'document',
            'loi', 'tribunal', 'accord', 'contrat', 'rapport', 'resultat', 'résultat',
        ]);
    }

    private function strongFactualActions(): array
    {
        return [
            // Arabic generic factual actions
            'اعلن', 'اعلنت', 'اكد', 'اكدت', 'نفي', 'نفت', 'قرر', 'قررت',
            'صادق', 'صادقت', 'وافق', 'وافقت', 'رفض', 'رفضت',
            'وقع', 'وقعت', 'امضي', 'امضت', 'تعاقد', 'تعاقدت',
            'عين', 'عينت', 'اقال', 'اقالت', 'استقال', 'استقالت',
            'الغى', 'الغت', 'اجل', 'اجلت', 'تاجل', 'تاجلت',
            'نشر', 'نشرت', 'صدر', 'اصدر', 'اصدرت',
            'اشتري', 'اشترت', 'باع', 'باعت', 'اطلق', 'اطلقت',
            'افتتح', 'افتتحت', 'اغلق', 'اغلقت', 'ارتفع', 'انخفض',
            'فاز', 'خسر', 'توفي', 'اعتقل', 'اعتقلت', 'زار', 'زارت',

            // English generic factual actions
            'announced', 'confirmed', 'denied', 'decided', 'approved', 'rejected',
            'signed', 'appointed', 'dismissed', 'resigned', 'cancelled', 'canceled',
            'postponed', 'published', 'issued', 'bought', 'sold', 'launched',
            'opened', 'closed', 'increased', 'decreased', 'won', 'lost',
            'died', 'arrested', 'visited',

            // French generic factual actions
            'annonce', 'annoncé', 'confirme', 'confirmé', 'dementi', 'démenti',
            'decide', 'décidé', 'approuve', 'approuvé', 'rejete', 'rejeté',
            'signe', 'signé', 'nomme', 'nommé', 'demis', 'démis',
            'demissionne', 'démissionné', 'annule', 'annulé',
            'reporte', 'reporté', 'publie', 'publié', 'achete', 'acheté',
            'vendu', 'lance', 'lancé', 'ouvert', 'ferme', 'fermé',
            'augmente', 'augmenté', 'baisse', 'baissé', 'gagne', 'gagné',
            'perdu', 'mort', 'arrete', 'arrêté', 'visite', 'visité',
        ];
    }

    private function softFactualActions(): array
    {
        return [
            // Soft/future/reporting actions can be verifiable only with enough concrete context
            'سيعلن', 'سيتم', 'من المنتظر', 'يتجه', 'قريب من', 'اقترب',
            'مفاوضات', 'في مفاوضات', 'مرشح', 'متوقع',

            'will announce', 'will visit', 'expected to', 'set to', 'close to',
            'in talks', 'negotiating', 'reportedly',

            'devrait', 'proche de', 'en négociation', 'en negociations',
            'selon', 'serait', 'va annoncer',
        ];
    }

    private function detectVaguenessLevel(string $text): string
    {
        $highVagueSignals = [
            'مفاجأة', 'مفاجاه', 'مدوية', 'مدويه', 'قريبا', 'قريباً',
            'في الساعات القادمة', 'في الساعات القادمه', 'مصادر خاصة',
            'مصادر خاصه', 'مصادر تؤكد', 'تسريب', 'زلزال', 'الحقيقة ستظهر',
            'الحقيقه ستظهر',
            'big surprise', 'coming soon', 'sources say', 'exclusive',
            'shocking', 'truth will come out',
            'grande surprise', 'prochainement', 'sources confirment',
        ];

        $mediumVagueSignals = [
            'عاجل', 'حصري', 'حسب مصادر', 'يقال', 'قريب من',
            'breaking', 'exclusive', 'reportedly', 'rumor', 'rumour',
            'urgent', 'selon des sources', 'rumeur',
        ];

        if ($this->containsAny($text, $highVagueSignals)) {
            return 'high';
        }

        if ($this->containsAny($text, $mediumVagueSignals)) {
            return 'medium';
        }

        return 'low';
    }

    private function detectClaimType(string $text): string
    {
        if ($this->containsAny($text, ['وزارة', 'حكومه', 'حكومة', 'رئيس', 'برلمان', 'minister', 'government', 'president', 'parliament'])) {
            return 'politics';
        }

        if ($this->containsAny($text, ['شركة', 'شركه', 'سعر', 'اسعار', 'أسعار', 'بنك', 'company', 'price', 'bank', 'market', 'business'])) {
            return 'business';
        }

        if ($this->containsAny($text, ['محكمه', 'محكمة', 'قانون', 'حكم', 'سجن', 'court', 'law', 'sentenced', 'prison'])) {
            return 'legal';
        }

        if ($this->containsAny($text, ['مستشفى', 'مرض', 'صحه', 'صحة', 'دواء', 'hospital', 'health', 'medicine', 'disease'])) {
            return 'health';
        }

        if ($this->containsAny($text, ['طقس', 'امطار', 'حراره', 'weather', 'rain', 'temperature'])) {
            return 'weather';
        }

        if ($this->containsAny($text, ['مباراه', 'مباراة', 'لاعب', 'مدرب', 'نادي', 'منتخب', 'match', 'player', 'coach', 'club', 'team'])) {
            return 'sports';
        }

        if ($this->containsAny($text, ['اعلن', 'اعلنت', 'بيان', 'بلاغ', 'announced', 'statement', 'communique', 'communiqué'])) {
            return 'official_announcement';
        }

        return 'general';
    }
}