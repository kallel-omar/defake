<?php

namespace App\Service;

class EvidenceDecisionService
{
    public function decide(string $claim, array $items): array
    {
        $claim = trim(mb_strtolower($claim));

        if ($claim === '' || empty($items)) {
            return $this->fallbackDecision();
        }

        $relevantIndexes = [];

        foreach (array_slice($items, 0, 5, true) as $index => $item) {
            $text = mb_strtolower(
                ($item['title'] ?? '') . ' ' .
                ($item['snippet'] ?? '') . ' ' .
                ($item['source'] ?? '')
            );

            if ($this->looksRelevant($claim, $text)) {
                $relevantIndexes[] = (int) $index;
            }
        }

        if (count($relevantIndexes) >= 2) {
            return [
                'status' => 'SUPPORTED',
                'supportCount' => count($relevantIndexes),
                'relevantIndexes' => $relevantIndexes,
                'reason' => 'Multiple search results appear relevant to the main claim.',
            ];
        }

        if (count($relevantIndexes) === 1) {
            return [
                'status' => 'PARTIALLY_SUPPORTED',
                'supportCount' => 1,
                'relevantIndexes' => $relevantIndexes,
                'reason' => 'One search result appears related to the main claim.',
            ];
        }

        return [
            'status' => 'UNSUPPORTED',
            'supportCount' => 0,
            'relevantIndexes' => [],
            'reason' => 'No search result appeared clearly relevant to the main claim.',
        ];
    }

    private function looksRelevant(string $claim, string $text): bool
    {
        $words = preg_split('/\s+/u', $claim);
        $words = array_filter($words, fn ($word) => mb_strlen($word) >= 4);

        if (empty($words)) {
            return false;
        }

        $matches = 0;

        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    private function fallbackDecision(): array
    {
        return [
            'status' => 'UNSUPPORTED',
            'supportCount' => 0,
            'relevantIndexes' => [],
            'reason' => 'No claim or evidence items were available for comparison.',
        ];
    }
}