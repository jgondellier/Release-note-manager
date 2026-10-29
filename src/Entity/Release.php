<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationFormat;
use App\Enum\PublicationTarget;
use App\Enum\ReleaseStatus;
use App\Enum\TicketSyncState;
use App\Repository\ReleaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Release note source, rattachée à une version (Fix Version) Jira d'un projet.
 * Table « release_note » : RELEASE est un mot réservé MySQL.
 *
 * Règle métier : une seule release note par couple (projet, version Jira).
 * Les trois formats (IT, Fonctionnel, Communication) sont des variantes de cette source.
 */
#[ORM\Entity(repositoryClass: ReleaseRepository::class)]
#[ORM\Table(name: 'release_note')]
#[ORM\UniqueConstraint(name: 'uniq_release_project_version', columns: ['project_id', 'jira_version_id'])]
#[ORM\Index(name: 'idx_release_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Release
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'releases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Project $project = null;

    #[ORM\Column(length: 50)]
    private string $jiraVersionId = '';

    #[ORM\Column(length: 255)]
    private string $jiraVersionName = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $jiraVersionDescription = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $jiraVersionReleased = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $jiraVersionReleaseDate = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre éditorial est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    #[ORM\Column(length: 30, enumType: ReleaseStatus::class)]
    private ReleaseStatus $status = ReleaseStatus::Draft;

    /** Introduction du format IT (périmètre technique). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $itIntro = null;

    /** Présentation de la version pour le format Fonctionnel. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $functionalIntro = null;

    /** Titre accrocheur du format Communication (à défaut : titre éditorial). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $communicationTitle = null;

    /** Introduction courte du format Communication. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $communicationIntro = null;

    /** Liens utiles communs (une ligne par lien : « Libellé | https://… »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $usefulLinks = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** @var array<string, mixed>|null Résumé de la dernière synchronisation Jira. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastSyncReport = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $lastSyncError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** Statut avant archivage (pour la désarchivation). */
    #[ORM\Column(length: 30, nullable: true, enumType: ReleaseStatus::class)]
    private ?ReleaseStatus $statusBeforeArchive = null;

    /** @var Collection<int, ReleaseSection> */
    #[ORM\OneToMany(targetEntity: ReleaseSection::class, mappedBy: 'release', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $sections;

    /** @var Collection<int, JiraTicket> */
    #[ORM\OneToMany(targetEntity: JiraTicket::class, mappedBy: 'release', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tickets;

    /** @var Collection<int, Publication> */
    #[ORM\OneToMany(targetEntity: Publication::class, mappedBy: 'release', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $publications;

    #[ORM\OneToOne(targetEntity: AcceptanceReport::class, mappedBy: 'release', cascade: ['persist', 'remove'])]
    private ?AcceptanceReport $acceptanceReport = null;

    /** @var Collection<int, PublicationContainer> pages de regroupement Confluence (une par instance) */
    #[ORM\OneToMany(targetEntity: PublicationContainer::class, mappedBy: 'release', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $containers;

    public function __construct()
    {
        $this->sections = new ArrayCollection();
        $this->tickets = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->containers = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getJiraVersionId(): string
    {
        return $this->jiraVersionId;
    }

    public function setJiraVersionId(string $jiraVersionId): static
    {
        $this->jiraVersionId = $jiraVersionId;

        return $this;
    }

    public function getJiraVersionName(): string
    {
        return $this->jiraVersionName;
    }

    public function setJiraVersionName(string $jiraVersionName): static
    {
        $this->jiraVersionName = $jiraVersionName;

        return $this;
    }

    public function getJiraVersionDescription(): ?string
    {
        return $this->jiraVersionDescription;
    }

    public function setJiraVersionDescription(?string $description): static
    {
        $this->jiraVersionDescription = $description;

        return $this;
    }

    public function isJiraVersionReleased(): bool
    {
        return $this->jiraVersionReleased;
    }

    public function setJiraVersionReleased(bool $released): static
    {
        $this->jiraVersionReleased = $released;

        return $this;
    }

    public function getJiraVersionReleaseDate(): ?\DateTimeImmutable
    {
        return $this->jiraVersionReleaseDate;
    }

    public function setJiraVersionReleaseDate(?\DateTimeImmutable $date): static
    {
        $this->jiraVersionReleaseDate = $date;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    public function getReleaseDate(): ?\DateTimeImmutable
    {
        return $this->releaseDate;
    }

    /**
     * Année de mise en production, pour le classement par année dans Confluence :
     * date de MEP saisie, sinon date de livraison Jira, sinon date de création de la release.
     */
    public function getMepYear(): int
    {
        return (int) ($this->releaseDate ?? $this->jiraVersionReleaseDate ?? $this->getCreatedAt() ?? new \DateTimeImmutable())->format('Y');
    }

    public function setReleaseDate(?\DateTimeImmutable $releaseDate): static
    {
        $this->releaseDate = $releaseDate;

        return $this;
    }

    public function getStatus(): ReleaseStatus
    {
        return $this->status;
    }

    public function setStatus(ReleaseStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isArchived(): bool
    {
        return ReleaseStatus::Archived === $this->status;
    }

    public function archive(): static
    {
        if (!$this->isArchived()) {
            $this->statusBeforeArchive = $this->status;
            $this->status = ReleaseStatus::Archived;
            $this->archivedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function unarchive(): static
    {
        if ($this->isArchived()) {
            $this->status = $this->statusBeforeArchive ?? ReleaseStatus::Draft;
            $this->statusBeforeArchive = null;
            $this->archivedAt = null;
        }

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getItIntro(): ?string
    {
        return $this->itIntro;
    }

    public function setItIntro(?string $itIntro): static
    {
        $this->itIntro = self::nullIfBlank($itIntro);

        return $this;
    }

    public function getFunctionalIntro(): ?string
    {
        return $this->functionalIntro;
    }

    public function setFunctionalIntro(?string $functionalIntro): static
    {
        $this->functionalIntro = self::nullIfBlank($functionalIntro);

        return $this;
    }

    public function getCommunicationTitle(): ?string
    {
        return $this->communicationTitle;
    }

    public function setCommunicationTitle(?string $communicationTitle): static
    {
        $this->communicationTitle = self::nullIfBlank($communicationTitle);

        return $this;
    }

    public function getCommunicationIntro(): ?string
    {
        return $this->communicationIntro;
    }

    public function setCommunicationIntro(?string $communicationIntro): static
    {
        $this->communicationIntro = self::nullIfBlank($communicationIntro);

        return $this;
    }

    public function getUsefulLinks(): ?string
    {
        return $this->usefulLinks;
    }

    public function setUsefulLinks(?string $usefulLinks): static
    {
        $this->usefulLinks = self::nullIfBlank($usefulLinks);

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    /** @return array<string, mixed>|null */
    public function getLastSyncReport(): ?array
    {
        return $this->lastSyncReport;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    /** @param array<string, mixed> $report */
    public function recordSync(array $report): static
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastSyncReport = $report;
        $this->lastSyncError = null;

        return $this;
    }

    public function recordSyncError(string $message): static
    {
        $this->lastSyncError = mb_substr($message, 0, 1000);

        return $this;
    }

    /** @return Collection<int, ReleaseSection> */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(ReleaseSection $section): static
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setRelease($this);
        }

        return $this;
    }

    public function removeSection(ReleaseSection $section): static
    {
        $this->sections->removeElement($section);

        return $this;
    }

    public function getNextSectionPosition(): int
    {
        $max = 0;
        foreach ($this->sections as $section) {
            $max = max($max, $section->getPosition());
        }

        return $max + 10;
    }

    /** @return Collection<int, JiraTicket> */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }

    public function addTicket(JiraTicket $ticket): static
    {
        if (!$this->tickets->contains($ticket)) {
            $this->tickets->add($ticket);
            $ticket->setRelease($this);
        }

        return $this;
    }

    public function removeTicket(JiraTicket $ticket): static
    {
        $ticket->getSection()?->removeTicket($ticket);
        $this->tickets->removeElement($ticket);

        return $this;
    }

    public function findTicketByKey(string $key): ?JiraTicket
    {
        foreach ($this->tickets as $ticket) {
            if (0 === strcasecmp($ticket->getJiraKey(), $key)) {
                return $ticket;
            }
        }

        return null;
    }

    public function findTicketByIssueId(string $issueId): ?JiraTicket
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->getJiraIssueId() === $issueId) {
                return $ticket;
            }
        }

        return null;
    }

    /** @return list<JiraTicket> Tickets actifs non rattachés à une section. */
    public function getUnassignedTickets(): array
    {
        return array_values($this->tickets->filter(
            static fn (JiraTicket $t) => null === $t->getSection() && !$t->isExcluded()
        )->toArray());
    }

    /** @return list<JiraTicket> */
    public function getExcludedTickets(): array
    {
        return array_values($this->tickets->filter(static fn (JiraTicket $t) => $t->isExcluded())->toArray());
    }

    /** @return list<JiraTicket> Tickets signalés par la dernière synchronisation (retirés/inaccessibles). */
    public function getFlaggedTickets(): array
    {
        return array_values($this->tickets->filter(
            static fn (JiraTicket $t) => TicketSyncState::Active !== $t->getSyncState()
        )->toArray());
    }

    /** @return Collection<int, Publication> */
    public function getPublications(): Collection
    {
        return $this->publications;
    }

    public function addPublication(Publication $publication): static
    {
        if (!$this->publications->contains($publication)) {
            $this->publications->add($publication);
            $publication->setRelease($this);
        }

        return $this;
    }

    public function findPublication(PublicationFormat $format, PublicationTarget $target): ?Publication
    {
        foreach ($this->publications as $publication) {
            if ($publication->getFormat() === $format && $publication->getTarget() === $target) {
                return $publication;
            }
        }

        return null;
    }

    public function getAcceptanceReport(): ?AcceptanceReport
    {
        return $this->acceptanceReport;
    }

    public function setAcceptanceReport(?AcceptanceReport $acceptanceReport): static
    {
        $this->acceptanceReport = $acceptanceReport;
        $acceptanceReport?->setRelease($this);

        return $this;
    }

    /** @return Collection<int, PublicationContainer> */
    public function getContainers(): Collection
    {
        return $this->containers;
    }

    public function findContainer(PublicationTarget $target): ?PublicationContainer
    {
        foreach ($this->containers as $container) {
            if ($container->getTarget() === $target) {
                return $container;
            }
        }

        return null;
    }

    public function addContainer(PublicationContainer $container): static
    {
        if (!$this->containers->contains($container)) {
            $this->containers->add($container);
            $container->setRelease($this);
        }

        return $this;
    }

    public function hasSuccessfulPublication(): bool
    {
        foreach ($this->publications as $publication) {
            if (null !== $publication->getPublishedAt()) {
                return true;
            }
        }

        return false;
    }

    public function getDisplayName(): string
    {
        return sprintf('%s — %s', $this->project?->getName() ?? '?', $this->jiraVersionName);
    }

    private static function nullIfBlank(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = str_replace("\r\n", "\n", $value);

        return '' === trim($value) ? null : $value;
    }
}
