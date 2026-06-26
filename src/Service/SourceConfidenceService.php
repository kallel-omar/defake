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
                'type' => 'unknown',
            ];
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);

        if ($this->isGovernmentSource($host)) {
            return [
                'score' => 90,
                'label' => 'Government / public authority',
                'type' => 'government',
            ];
        }

        if ($this->isInternationalOrganization($host)) {
            return [
                'score' => 85,
                'label' => 'International organization',
                'type' => 'organization',
            ];
        }

        if ($this->isKnownMedia($host)) {
            return [
                'score' => 75,
                'label' => 'Known media source',
                'type' => 'media',
            ];
        }

        if ($this->isSocialMedia($host)) {
            return [
                'score' => 30,
                'label' => 'Social media source',
                'type' => 'social',
            ];
        }

        if ($this->isOrganizationDomain($host)) {
            return [
                'score' => 55,
                'label' => 'Organization website',
                'type' => 'organization',
            ];
        }

        return [
            'score' => 35,
            'label' => 'Unverified web source',
            'type' => 'unknown',
        ];
    }

    private function isGovernmentSource(string $host): bool
    {
        return str_contains($host, '.gov.')
            || str_ends_with($host, '.gov')
            || str_contains($host, '.gouv.')
            || str_contains($host, 'government.')
            || str_contains($host, 'gouvernement.')
            || str_contains($host, 'presidence.')
            || str_contains($host, 'ministere.')
            || str_contains($host, 'ministry.');
    }

    private function isInternationalOrganization(string $host): bool
    {
        $organizations = [
            'un.org',
            'who.int',
            'worldbank.org',
            'imf.org',
            'fifa.com',
            'cafonline.com',
            'uefa.com',
            'olympics.com',
            'interpol.int',
            'europa.eu',
            'africa-union.org',
        ];

        foreach ($organizations as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownMedia(string $host): bool
    {
        $knownMedia = [
            // Tunisia
            'tap.info.tn',
            'mosaiquefm.net',
            'shemsfm.net',
            'radioexpressfm.com',
            'businessnews.com.tn',
            'webdo.tn',
            'kapitalis.com',
            'jawharafm.net',
            'diwanfm.net',
            'ifm.tn',
            'attessia.tv',
            'elhiwarettounsi.com',
            'hannibaltv.com.tn',
            'carthageplus.net',
            'telvzatv.com',

            // International
            'reuters.com',
            'apnews.com',
            'bbc.com',
            'france24.com',
            'afp.com',
            'aljazeera.com',
            'cnn.com',
            'theguardian.com',
            'nytimes.com',
            'washingtonpost.com',
            'lemonde.fr',
            'dw.com',
            'euronews.com',
            'skynews.com',
            'nbcnews.com',
            'abcnews.go.com',
            'cbsnews.com',
        ];

        foreach ($knownMedia as $media) {
            if ($host === $media || str_ends_with($host, '.' . $media)) {
                return true;
            }
        }

        return false;
    }

    private function isOrganizationDomain(string $host): bool
    {
        return str_ends_with($host, '.org')
            || str_ends_with($host, '.int')
            || str_ends_with($host, '.edu')
            || str_ends_with($host, '.ac.tn');
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
            'linkedin.com',
        ];

        foreach ($socialMedia as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                return true;
            }
        }

        return false;
    }
}