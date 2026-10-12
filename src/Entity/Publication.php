<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationFormat;
use App\Enum\PublicationStatus;
use App\Enum\PublicationTarget;
use App\Repository\PublicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping (release, format, cible Confluence) → page Confluence.
 * L'unicité de ce triplet garantit qu'une republication met à jour la même page.
 */
#[ORM\Entity(repositoryClass: PublicationRepository::class)]
#[ORM\Table(name: 'publication')]
#[ORM\UniqueConstraint(name: 'uniq_publication_target', columns: ['release_id', 'format', 'target'])]
#[ORM\Index(name: 'idx_publication_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Publication
{
    use TimestampableTrait;

    /** Au-delà, une publication « en cours » est considérée comme interrompue. */
    public const STALE_AFTER_SECONDS = 600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'publications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    #[ORM\Column(length: 20, enumType: PublicationFormat::class)]
    private PublicationFormat $format;

    #[ORM\Column(length: 20, enumType: PublicationTarget::class)]
    private PublicationTarget $target;

    #[ORM\Column(length: 20, enumType: PublicationStatus::class)]
    private PublicationStatus $status = PublicationStatus::Pending;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $spaceKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pageTitle = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $confluencePageId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $confluencePageUrl = null;

    #[ORM\Column(nullable: true)]
    private ?int $confluencePageVersion = null;

    /** Empreinte SHA-256 du contenu publié (détection des modifications ultérieures). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, PublicationAttempt> */
    #[ORM\OneToMany(targetEntity: PublicationAttempt::class, mappedBy: 'publication', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $attempts;

    public function __construct(PublicationFormat $format, PublicationTarget $target)
    {
        $this->format = $format;
        $this->target = $target;
        $this->attempts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): ?Release
    {
        return $this->release;
    }

    public function setRelease(?Release $release): static
    {
        $this->release = $release;

        return $this;
    }

    public function getFormat(): PublicationFormat
    {
        return $this->format;
    }

    public function getTarget(): PublicationTarget
    {
        return $this->target;
    }

    public function getStatus(): PublicationStatus
    {
        return $this->status;
    }

    public function isRunning(?\DateTimeImmutable $now = null): bool
    {
        if (PublicationStatus::InProgress !== $this->status || null === $this->startedAt) {
            return false;
        }
        $now ??= new \DateTimeImmutable();

        return ($now->getTimestamp() - $this->startedAt->getTimestamp()) < self::STALE_AFTER_SECONDS;
    }

    public function markStarted(string $spaceKey, string $pageTitle): static
    {
        $this->status = PublicationStatus::InProgress;
        $this->startedAt = new \DateTimeImmutable();
        $this->lastAttemptAt = $this->startedAt;
        $this->attemptCount++;
        $this->spaceKey = $spaceKey;
        $this->pageTitle = $pageTitle;

        return $this;
    }

    public function markSucceeded(string $pageId, string $pageUrl, ?int $pageVersion, string $contentHash): static
    {
        $this->status = PublicationStatus::Success;
        $this->confluencePageId = $pageId;
        $this->confluencePageUrl = $pageUrl;
        $this->confluencePageVersion = $pageVersion;
        $this->contentHash = $contentHash;
        $this->errorMessage = null;
        $this->publishedAt = new \DateTimeImmutable();
        $this->startedAt = null;

        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $this->status = PublicationStatus::Failed;
        $this->errorMessage = mb_substr($errorMessage, 0, 1000);
        $this->startedAt = null;

        return $this;
    }

    public function getSpaceKey(): ?string
    {
        return $this->spaceKey;
    }

    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }

    public function getConfluencePageId(): ?string
    {
        return $this->confluencePageId;
    }

    /** Oublie la page associée (ex. page supprimée côté Confluence). */
    public function forgetPage(): static
    {
        $this->confluencePageId = null;
        $this->confluencePageUrl = null;
        $this->confluencePageVersion = null;

        return $this;
    }

    public function getConfluencePageUrl(): ?string
    {
        return $this->confluencePageUrl;
    }

    public function getConfluencePageVersion(): ?int
    {
        return $this->confluencePageVersion;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return Collection<int, PublicationAttempt> */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    public function addAttempt(PublicationAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
            $attempt->setPublication($this);
        }

        return $this;
    }
}
