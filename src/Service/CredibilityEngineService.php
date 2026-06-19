<?php

namespace App\Service;

class CredibilityEngineService
{
    public function calculateSourceScore(string $internetEvidence): int
    {
        $score = 0;
        $text = strtolower($internetEvidence);

        $trustedSources = [
            'reuters.com',
            'bbc.com',
            'apnews.com',
            'fifa.com',
            'cafonline.com',
            'ftf.org.tn',
            'mosaiquefm.net',
            'jawharafm.net',
            'tap.info.tn',
        ];

        foreach ($trustedSources as $source) {
            if (str_contains($text, $source)) {
                $score += 5;
            }
        }

        $socialSources = [
            'facebook.com',
            'instagram.com',
            'x.com',
            'twitter.com',
            'tiktok.com',
        ];

        foreach ($socialSources as $source) {
            if (str_contains($text, $source)) {
                $score += 1;
            }
        }

        return min($score, 25);
    }

    public function calculateLanguageScore(string $postText): int
    {
        $score = 25;
        $text = strtolower($postText);

        $manipulativeWords = [
            'shocking',
            'urgent',
            'scandal',
            'breaking',
            'exclusive',
            '100%',
            'confirmed',
            'فضيحة',
            'عاجل',
            'حصري',
            'رسميا',
            'سبهلالا',
            'الشفافية',
            'المصداقية',
            'كيفاش',
            'شكون',
            'حيرة',
            'باش يصير فيكم',
            'راهم',
            'تدخيل الفلوس',
            'الفلوس',
            'مؤيد',
        ];

        foreach ($manipulativeWords as $word) {
            if (str_contains($text, strtolower($word))) {
                $score -= 3;
            }
        }

        if (substr_count($postText, '?') + substr_count($postText, '؟') >= 3) {
            $score -= 5;
        }

        if (substr_count($postText, '!') >= 3) {
            $score -= 5;
        }

        if (preg_match('/[😂🤣😱🔥🚨]{3,}/u', $postText)) {
            $score -= 5;
        }

        return max(0, min(25, $score));
    }
    public function calculateVerificationScore(
    int $evidenceScore,
    int $sourceScore,
    int $languageScore
): int {
    $score = 25;

    if ($evidenceScore <= 5) {
        $score -= 10;
    } elseif ($evidenceScore <= 10) {
        $score -= 5;
    }

    if ($sourceScore <= 2) {
        $score -= 10;
    } elseif ($sourceScore <= 5) {
        $score -= 5;
    }

    if ($languageScore <= 5) {
        $score -= 5;
    }

    return max(0, min(25, $score));
}
}