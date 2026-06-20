<?php

namespace App\Service;

class EvidenceDecisionService
{
    public function decide(string $claim, array $items): array
    {
        $supportCount = 0;
        $keywords = $this->extractKeywords($claim);

        foreach ($items as $item) {
            $text = strtolower(
                ($item['title'] ?? '') . ' ' .
                ($item['snippet'] ?? '') . ' ' .
                ($item['source'] ?? '')
            );

            $matches = 0;

            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $matches++;
                }
            }

            if ($matches >= max(2, (int) floor(count($keywords) / 2))) {
                $supportCount++;
            }
        }
                if ($supportCount >= 3) {
                    return [
                        'status' => 'SUPPORTED',
                        'supportCount' => $supportCount,
                        'reason' => 'Multiple search results support the main claim.',
                    ];
                }

                return [
                    'status' => 'UNCLEAR',
                    'supportCount' => $supportCount,
                    'reason' => 'Not enough matching evidence was found.',
                ];
            }
    private function extractKeywords(string $claim): array
{
    $claim = strtolower($claim);

    $claim = preg_replace('/[^a-z0-9\s]/', ' ', $claim);

    $words = array_filter(explode(' ', $claim), function (string $word) {
        return strlen($word) >= 4;
    });

    $stopWords = [
        'this', 'that', 'with', 'from', 'have', 'will',
        'about', 'match', 'game', 'news', 'official',
        'here', 'there', 'what', 'when', 'where'
    ];

    $keywords = array_values(array_diff($words, $stopWords));

    return array_slice(array_unique($keywords), 0, 8);
}

}