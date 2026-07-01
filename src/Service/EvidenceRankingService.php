<?php

namespace App\Service;

class EvidenceRankingService
{
    public function scoreEvidenceRelevance(array $item, string $claim): int
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
}