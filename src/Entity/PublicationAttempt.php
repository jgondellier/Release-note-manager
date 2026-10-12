<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationStatus;
use App\Repository\PublicationAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique immuable de chaque tentative de publication (succès ou échec).
 */
#[ORM\Entity(repositoryClass: PublicationAttemptRepository::class)]
#[ORM\Table(name: 'publication_attempt')]
#[ORM\Index(name: 'idx_attempt_created', columns: ['created_at'])]
class PublicationAttempt
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Publication $publication = null;

    #[ORM\Column(length: 20, enumType: PublicationStatus::class)]
    private PublicationStatus $status;

    #[ORM\Column(length: 20)]
    private string $action;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $message;

    #[ORM\Column(nullable: true)]
    private ?int $pageVersion;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(PublicationStatus $status, string $action, ?string $message = null, ?int $pageVersion = null, ?int $durationMs = null)
    {
        $this->status = $status;
        $this->action = $action;
        $this->message = null === $message ? null : mb_substr($message, 0, 1000);
        $this->pageVersion = $pageVersion;
        $this->durationMs = $durationMs;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): ?Publication
    {
        return $this->publication;
    }

    public function setPublication(?Publication $publication): static
    {
        $this->publication = $publication;

        return $this;
    }

    public function getStatus(): PublicationStatus
    {
        return $this->status;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Page créée',
            self::ACTION_UPDATED => 'Page mise à jour',
            default => 'Échec',
        };
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getPageVersion(): ?int
    {
        return $this->pageVersion;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
