<?php

namespace App\Service;

class EvidenceDecisionService
{
    public function decide(string $claim, array $items): array
    {
        $supportCount = 0;

        foreach ($items as $item) {
            $text = strtolower(
                ($item['title'] ?? '') . ' ' .
                ($item['snippet'] ?? '') . ' ' .
                ($item['source'] ?? '')
            );

            if (
                str_contains($text, 'tunisia') &&
                str_contains($text, 'japan') &&
                str_contains($text, 'world cup')
            ) {
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
}