<?php

namespace App\Service;

use App\Exception\AnalysisConfigurationException;
use App\Exception\AnalysisPermanentException;
use App\Exception\AnalysisTransientException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class OfficialSourceDetectorService
{
    private const ALLOWED_CATEGORIES = [
        'government',
        'ministry',
        'public_authority',
        'company',
        'club',
        'federation',
        'organization',
        'media',
        'fan_page',
        'journalist',
        'commentary',
        'unknown',
    ];

    public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly GroqAiService $groqAiService,
    private readonly string $serperApiKey,
    private readonly EntityIdentityResolverService $entityIdentityResolverService,
    private readonly LoggerInterface $logger,
) {
}

    public function detect(
    array $sourceContext,
    string $postText = '',
    string $mainClaim = ''
): array
    {
        $pageName = $this->extractPageName($sourceContext);

        if ($pageName === '') {
            return $this->result(
                false,
                'unknown',
                0,
                'No Facebook page name was available.'
            );
        }

        $googleEvidence = $this->searchOfficialEvidence($pageName);

        $prompt = $this->buildOfficialPagePrompt(
    $pageName,
    $sourceContext,
    $postText,
    $mainClaim,
    $googleEvidence
);

        $data = $this->groqAiService->askJson($prompt, 1000);

        if (empty($data)) {
            return $this->fallbackDetection($pageName, $googleEvidence);
        }

    $official = (bool) ($data['official'] ?? false);
$selfAnnouncement = (bool) ($data['selfAnnouncement'] ?? false);

$claimActor = trim((string) ($data['claimActor'] ?? ''));
$sameOrganization = (bool) ($data['sameOrganization'] ?? false);

        $category = $this->normalizeCategory(
            (string) ($data['category'] ?? 'unknown')
        );

        $confidence = $this->normalizeConfidence(
            $data['confidence'] ?? 0
        );

        $reason = trim(
            (string) ($data['reason'] ?? 'No reason provided.')
        ) ?: 'No reason provided.';

        $nonOfficialCategories = [
            'media',
            'fan_page',
            'journalist',
            'commentary',
            'unknown',
        ];

        if (in_array($category, $nonOfficialCategories, true)) {
         $official = false;
        $selfAnnouncement = false;

                $reason .= ' The detected category is not treated as an official organization source.';
            }

            if ($official && $confidence < 75) {
                $official = false;
                $selfAnnouncement = false;

                $reason .= ' Confidence is too low to treat this page as official.';
            }

            if (!$official) {
                $selfAnnouncement = false;
            }
            $identityResolution = null;

// AI models can fail to recognize the same organization when its name
// appears in another language, transliteration, abbreviation, or alias.
//
// Only use entity resolution as a fallback when:
// - the Facebook source itself was confirmed official;
// - the model did not accept the post as a self-announcement;
// - the model extracted an actor from the claim.
if (
    $official
    && !$selfAnnouncement
    && $claimActor !== ''
) {
    $identityResolution = $this->entityIdentityResolverService->resolve(
        $pageName,
        $claimActor
    );

    if (($identityResolution['sameEntity'] ?? false) === true) {
        $sameOrganization = true;
        $selfAnnouncement = true;

        $reason .= sprintf(
            ' The claim actor and the official page were independently resolved as the same real-world entity using %s.',
            (string) ($identityResolution['method'] ?? 'entity resolution')
        );
    }
}

           return $this->result(
            $official,
            $category,
            $confidence,
            $reason,
            $selfAnnouncement
);
        }

    public function evaluateEvidenceUrl(
        string $url,
        string $title = '',
        string $snippet = '',
        string $claim = ''
    ): array {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return $this->result(
                false,
                'unknown',
                0,
                'Invalid evidence URL.'
            );
        }

        if (!$this->isSocialEvidenceHost($host)) {
            return $this->result(
                true,
                'organization',
                60,
                'This is a website source. Trust level should be decided by the source confidence engine.'
            );
        }

        $prompt = $this->buildEvidenceUrlPrompt($url, $title, $snippet, $claim);

        $data = $this->groqAiService->askJson($prompt, 800);

        if (empty($data)) {
            return $this->result(
                false,
                'unknown',
                40,
                'Could not verify whether this social media source is official or trusted.'
            );
        }

        return $this->result(
            (bool) ($data['official'] ?? false),
            $this->normalizeCategory((string) ($data['category'] ?? 'unknown')),
            $this->normalizeConfidence($data['confidence'] ?? 0),
            trim((string) ($data['reason'] ?? 'No reason provided.')) ?: 'No reason provided.'
        );
    }

    private function buildOfficialPagePrompt(
    string $pageName,
    array $sourceContext,
    string $postText,
    string $mainClaim,
    string $googleEvidence
): string {
        $sourceJson = $this->safeJson($sourceContext);

        return <<<PROMPT
You are DeFake's official-source detector.

You must make TWO separate decisions:

1. Is this Facebook page genuinely the official page of the organization?
2. Is the claim in this specific post a first-party announcement about the organization's own activity, decision, member, service, event, transaction, appointment, signing, release, sanction, result, or another matter it directly controls?

These are separate decisions.

Facebook page name:
{$pageName}

Facebook source context:
{$sourceJson}

Post text:
{$postText}
Main factual claim extracted from the post:
{$mainClaim}

Important:
Use the MAIN FACTUAL CLAIM when deciding selfAnnouncement.
Determine who performs the action in the claim and whether that actor is the organization controlling this Facebook page.
Do not return selfAnnouncement=false merely because the post contains other names, such as a player, employee, customer, opponent, minister, executive, or third party involved in the organization's own action.

Google evidence:
{$googleEvidence}

Official means the page belongs directly to:
- a sports club
- a company
- a ministry
- a government or public authority
- a federation
- an official organization

Return official=true only if there is strong evidence that the page itself is controlled by the organization.

Important:
- A page name matching a famous club, company, ministry, person, or organization is NOT enough.
- A verified badge alone is NOT enough.
- A media/news page reporting about a club is not official.
- A fan/supporter page is not official.
- If the page is only talking ABOUT the organization, return false.
- If uncertain, return false.
- If Google evidence contains "official page", "page officielle", "official website", or "site officiel", this strongly supports official=true.
For "official":
- Judge whether the page itself genuinely belongs to the organization.
- The page may still be official even when a specific post discusses another organization.

For "selfAnnouncement":
- Return true only when the claim concerns something the organization itself did, decided, announced, controls, manages, employs, signed, appointed, released, organized, published, or is directly responsible for.
- Return false when the post is reporting a claim about another independent organization or event outside its authority.
- Return false if the relationship is unclear.
When deciding selfAnnouncement, compare organizations by real-world identity, not exact spelling.

Important multilingual identity rules:
- The same organization may appear under different languages, translations, abbreviations, transliterations, acronyms, or commonly used names.
- Do not treat two names as different organizations merely because one is Arabic and the other is French or English.
- Determine whether the claim's subject refers to the same real-world organization that controls the Facebook page.
- First identify the actor/subject performing the claimed action.
- Then determine whether that actor and the Facebook page organization are the same real-world entity.
- If they are the same entity and the action is under that organization's control, selfAnnouncement=true.

Return ONLY valid JSON:

{
  "official": false,
  "selfAnnouncement": false,
  "claimActor": "organization or person performing the main claimed action",
  "sameOrganization": false,
  "category": "unknown",
  "confidence": 0,
  "reason": "short reason"
}

Allowed categories:
government, ministry, public_authority, company, club, federation, organization, media, fan_page, journalist, commentary, unknown
PROMPT;
    }

    private function buildEvidenceUrlPrompt(
        string $url,
        string $title,
        string $snippet,
        string $claim
    ): string {
        return <<<PROMPT
Decide whether this social media evidence source is official or trusted.

Evidence URL:
{$url}

Evidence title:
{$title}

Evidence snippet:
{$snippet}

Claim being checked:
{$claim}

Official or trusted means the source appears to belong to:
- an official club
- a federation
- a government or ministry
- a company
- a public authority
- an official organization
- a trusted media organization

Return false if the source appears to be:
- random user
- fan page
- supporter page
- commentary page
- meme page
- rumor page
- personal account
- unknown social media account

Important:
- Do not trust a social media source only because it contains the name of a famous club, company, person, or organization.
- If uncertain, return false.

Return ONLY valid JSON:

{
  "official": false,
  "category": "unknown",
  "confidence": 0,
  "reason": "short reason"
}

Allowed categories:
government, ministry, public_authority, company, club, federation, organization, media, fan_page, journalist, commentary, unknown
PROMPT;
    }

    private function extractPageName(array $sourceContext): string
{
    // Prefer the human-readable Facebook display name.
    // Apify's pageName may sometimes contain a compact username/slug.
    $userName = trim((string) ($sourceContext['userName'] ?? ''));

    if ($userName !== '') {
        return $userName;
    }

    $pageName = trim((string) ($sourceContext['pageName'] ?? ''));

    if ($pageName !== '') {
        return $pageName;
    }

    $name = trim((string) ($sourceContext['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return '';
}

    private function searchOfficialEvidence(string $pageName): string
    {
        if ($this->serperApiKey === '') {
            return 'No Google evidence found because SERPER_API_KEY is missing.';
        }

        $query = sprintf(
            '%s official Facebook page page officielle official website site officiel',
            $pageName
        );

        try {
            $response = $this->httpClient->request('POST', 'https://google.serper.dev/search', [
                'headers' => [
                    'X-API-KEY' => $this->serperApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'q' => $query,
                    'num' => 5,
                ],
                'timeout' => 20,
            ]);

            $statusCode = $response->getStatusCode();

            if (in_array($statusCode, [408, 409, 425, 429, 500, 502, 503, 504], true)) {
                throw new AnalysisTransientException(
                    'Official source evidence search temporary API error: HTTP ' . $statusCode,
                    'Evidence search is temporarily unavailable. DeFake will retry shortly.'
                );
            }

            if (in_array($statusCode, [401, 403], true)) {
                throw new AnalysisConfigurationException(
                    'Official source evidence search authentication failed: HTTP ' . $statusCode,
                    'Evidence search is not configured correctly. Please try again later.'
                );
            }

            if ($statusCode >= 400) {
                throw new AnalysisPermanentException(
                    'Official source evidence search rejected the request: HTTP ' . $statusCode,
                    'Evidence search rejected this request. Please try again with different content.'
                );
            }

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Official source evidence search failed.', [
                'pageName' => $pageName,
                'exception' => $e,
            ]);

            throw new AnalysisTransientException(
                'Official source evidence search connection failed: ' . $e->getMessage(),
                'Evidence search could not be reached. DeFake will retry shortly.',
                previous: $e
            );
        } catch (DecodingExceptionInterface $e) {
            $this->logger->warning('Official source evidence search returned invalid JSON.', [
                'pageName' => $pageName,
                'exception' => $e,
            ]);

            throw new AnalysisTransientException(
                'Official source evidence search returned invalid JSON: ' . $e->getMessage(),
                'Evidence search returned an invalid response. DeFake will retry shortly.',
                previous: $e
            );
        }

        $items = $data['organic'] ?? [];

        if (empty($items)) {
            return 'No Google evidence found.';
        }

        $results = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $snippet = trim((string) ($item['snippet'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));

            $results[] = "Title: {$title}\nSnippet: {$snippet}\nLink: {$link}";
        }

        return implode("\n\n", $results);
    }

    private function fallbackDetection(string $pageName, string $googleEvidence): array
    {
        $text = mb_strtolower($pageName . ' ' . $googleEvidence);

        $unofficialSignals = [
            'fan page',
            'supporter',
            'supporters',
            'unofficial',
            'non officiel',
            'غير رسمية',
            'news',
            'media',
            'journalist',
            'rumor',
            'rumour',
            'commentary',
        ];

        foreach ($unofficialSignals as $signal) {
            if (str_contains($text, $signal)) {
                return $this->result(
                    false,
                    'unknown',
                    70,
                    'Google evidence suggests this may be a fan, media, supporter, or unofficial page.'
                );
            }
        }

        $strongOfficialSignals = [
            'official facebook page',
            'official page',
            'page officielle',
            'site officiel',
            'official website',
            'verified official',
            'facebook officiel',
        ];

        foreach ($strongOfficialSignals as $signal) {
            if (str_contains($text, $signal)) {
                return $this->result(
                    true,
                    $this->guessCategory($pageName),
                    75,
                    'Google evidence contains strong official-source wording for this Facebook page or organization.'
                );
            }
        }

        return $this->result(
            false,
            'unknown',
            40,
            'The system could not confirm from Google evidence that this is an official page.'
        );
    }

    private function guessCategory(string $pageName): string
    {
        $name = mb_strtolower($pageName);

        if (
            str_contains($name, 'ministère') ||
            str_contains($name, 'ministere') ||
            str_contains($name, 'ministry') ||
            str_contains($name, 'وزارة')
        ) {
            return 'ministry';
        }

        if (
            str_contains($name, 'gouvernement') ||
            str_contains($name, 'government') ||
            str_contains($name, 'public authority') ||
            str_contains($name, 'الهيئة') ||
            str_contains($name, 'الحكومة')
        ) {
            return 'government';
        }

        if (
            str_contains($name, 'fédération') ||
            str_contains($name, 'federation') ||
            str_contains($name, 'fifa') ||
            str_contains($name, 'caf') ||
            str_contains($name, 'جامعة')
        ) {
            return 'federation';
        }

        if (
            str_contains($name, 'club') ||
            str_contains($name, 'sportive') ||
            str_contains($name, 'sportif') ||
            str_contains($name, 'fc') ||
            str_contains($name, 'sc') ||
            str_contains($name, 'espérance') ||
            str_contains($name, 'esperance') ||
            str_contains($name, 'taraji') ||
            str_contains($name, 'الترجي') ||
            str_contains($name, 'النادي')
        ) {
            return 'club';
        }

        if (
            str_contains($name, 'company') ||
            str_contains($name, 'group') ||
            str_contains($name, 'bank') ||
            str_contains($name, 'airways') ||
            str_contains($name, 'airlines') ||
            str_contains($name, 'شركة') ||
            str_contains($name, 'بنك')
        ) {
            return 'company';
        }

        return 'organization';
    }

    private function isSocialEvidenceHost(string $host): bool
    {
        return str_contains($host, 'facebook.com')
            || str_contains($host, 'x.com')
            || str_contains($host, 'twitter.com')
            || str_contains($host, 'instagram.com')
            || str_contains($host, 'tiktok.com')
            || str_contains($host, 'youtube.com')
            || str_contains($host, 'linkedin.com');
    }

    private function normalizeCategory(string $category): string
    {
        $category = trim($category);

        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return 'unknown';
        }

        return $category;
    }

    private function normalizeConfidence(mixed $confidence): int
    {
        if (!is_numeric($confidence)) {
            return 0;
        }

        $confidence = (float) $confidence;

        if ($confidence > 0 && $confidence <= 1) {
            $confidence *= 100;
        }

        return max(0, min(100, (int) round($confidence)));
    }

    private function safeJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }

   private function result(
    bool $official,
    string $category,
    int $confidence,
    string $reason,
    bool $selfAnnouncement = false
): array {
    return [
        'official' => $official,
        'selfAnnouncement' => $official && $selfAnnouncement,
        'category' => $this->normalizeCategory($category),
        'confidence' => max(0, min(100, $confidence)),
        'reason' => $reason,
    ];
}
}
