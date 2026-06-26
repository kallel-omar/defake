<?php

namespace App\Entity;

use App\Repository\PostCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostCheckRepository::class)]
class PostCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $url = null;

    #[ORM\Column(length: 50)]
    private ?string $platform = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $urlHash = null;

    #[ORM\Column(nullable: true)]
    private ?int $evidenceScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $languageScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $verificationScore = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $evidenceReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $languageReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verificationReason = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contentSummary = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $processingStep = null;

    #[ORM\Column(nullable: true)]
    private ?array $evidenceSources = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mainClaim = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function setVerdict(?string $verdict): static
    {
        $this->verdict = $verdict;

        return $this;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): static
    {
        $this->explanation = $explanation;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
    public function getUrlHash(): ?string
    {
        return $this->urlHash;
    }

    public function setUrlHash(string $urlHash): static
    {
        $this->urlHash = $urlHash;

        return $this;
    }

    public function getEvidenceScore(): ?int
    {
        return $this->evidenceScore;
    }

    public function setEvidenceScore(?int $evidenceScore): static
    {
        $this->evidenceScore = $evidenceScore;

        return $this;
    }

    public function getSourceScore(): ?int
    {
        return $this->sourceScore;
    }

    public function setSourceScore(?int $sourceScore): static
    {
        $this->sourceScore = $sourceScore;

        return $this;
    }

    public function getLanguageScore(): ?int
    {
        return $this->languageScore;
    }

    public function setLanguageScore(?int $languageScore): static
    {
        $this->languageScore = $languageScore;

        return $this;
    }

    public function getVerificationScore(): ?int
    {
        return $this->verificationScore;
    }

    public function setVerificationScore(?int $verificationScore): static
    {
        $this->verificationScore = $verificationScore;

        return $this;
    }




    public function getEvidenceReason(): ?string
{
    return $this->evidenceReason;
}

public function setEvidenceReason(?string $evidenceReason): static
{
    $this->evidenceReason = $evidenceReason;

    return $this;
}

public function getSourceReason(): ?string
{
    return $this->sourceReason;
}

public function setSourceReason(?string $sourceReason): static
{
    $this->sourceReason = $sourceReason;

    return $this;
}

public function getLanguageReason(): ?string
{
    return $this->languageReason;
}

public function setLanguageReason(?string $languageReason): static
{
    $this->languageReason = $languageReason;

    return $this;
}

public function getVerificationReason(): ?string
{
    return $this->verificationReason;
}

public function setVerificationReason(?string $verificationReason): static
{
    $this->verificationReason = $verificationReason;

    return $this;
}

public function getContentType(): ?string
{
    return $this->contentType;
}

public function setContentType(?string $contentType): static
{
    $this->contentType = $contentType;

    return $this;
}

public function getContentTitle(): ?string
{
    return $this->contentTitle;
}

public function setContentTitle(?string $contentTitle): static
{
    $this->contentTitle = $contentTitle;

    return $this;
}

public function getContentSummary(): ?string
{
    return $this->contentSummary;
}

public function setContentSummary(?string $contentSummary): static
{
    $this->contentSummary = $contentSummary;

    return $this;
}

public function getProcessingStep(): ?string
{
    return $this->processingStep;
}

public function setProcessingStep(?string $processingStep): static
{
    $this->processingStep = $processingStep;

    return $this;
}

public function getEvidenceSources(): ?array
{
    return $this->evidenceSources;
}

public function setEvidenceSources(?array $evidenceSources): static
{
    $this->evidenceSources = $evidenceSources;

    return $this;
}

public function getMainClaim(): ?string
{
    return $this->mainClaim;
}

public function setMainClaim(?string $mainClaim): static
{
    $this->mainClaim = $mainClaim;

    return $this;
}
}