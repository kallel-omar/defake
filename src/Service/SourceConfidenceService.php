<?php

namespace App\Service;

class SourceConfidenceService
{
    public function score(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return [
                'score' => 20,
                'label' => 'Unknown source',
            ];
        }

        $host = strtolower(str_replace('www.', '', $host));

        if ($this->isOfficialSource($host)) {
            return [
                'score' => 90,
                'label' => 'Official source',
            ];
        }

        if ($this->isKnownMedia($host)) {
            return [
                'score' => 75,
                'label' => 'Known media source',
            ];
        }

        if ($this->isSocialMedia($host)) {
            return [
                'score' => 40,
                'label' => 'Social media source',
            ];
        }

        return [
            'score' => 50,
            'label' => 'Unverified web source',
        ];
    }

    private function isOfficialSource(string $host): bool
    {
        return str_ends_with($host, '.gov') ||
            str_ends_with($host, '.gov.tn') ||
            str_ends_with($host, '.org.tn') ||
            str_contains($host, 'interieur.gov') ||
            str_contains($host, 'defense.tn') ||
            str_contains($host, 'presidence.tn');
    }

    private function isKnownMedia(string $host): bool
    {
        $knownMedia = [
            'tap.info.tn',
            'mosaiquefm.net',
            'shemsfm.net',
            'radioexpressfm.com',
            'businessnews.com.tn',
            'webdo.tn',
            'kapitalis.com',
            'bbc.com',
            'france24.com',
            'reuters.com',
            'apnews.com',
            'aljazeera.com',
            'jawharafm.net',
            'diwanfm.net',
            'ifm.tn',
            'shemsfm.net',
            // Main portal for public radios
            
            // TVs
            'attessia.tv',
            'elhiwarettounsi.com',
            'hannibaltv.com.tn',
            'carthageplus.net',
            'telvzatv.com',
            ];

        foreach ($knownMedia as $media) {
            if ($host === $media || str_ends_with($host, '.' . $media)) {
                return true;
            }
        }

        return false;
    }

    private function isSocialMedia(string $host): bool
    {
        $socialMedia = [
            'facebook.com',
            'x.com',
            'twitter.com',
            'tiktok.com',
            'instagram.com',
            'youtube.com',
        ];

        foreach ($socialMedia as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                return true;
            }
        }

        return false;
    }
}