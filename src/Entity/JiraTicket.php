<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TicketSyncState;
use App\Repository\JiraTicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ticket Jira importé dans une release. Les champs de ce ticket sont des
 * métadonnées Jira, mises à jour lors des synchronisations ; ils ne
 * contiennent aucun wording éditorial (celui-ci est porté par ReleaseSection).
 */
#[ORM\Entity(repositoryClass: JiraTicketRepository::class)]
#[ORM\Table(name: 'jira_ticket')]
#[ORM\UniqueConstraint(name: 'uniq_ticket_release_key', columns: ['release_id', 'jira_key'])]
#[ORM\UniqueConstraint(name: 'uniq_ticket_release_issue', columns: ['release_id', 'jira_issue_id'])]
#[ORM\Index(name: 'idx_ticket_section_position', columns: ['section_id', 'position'])]
#[ORM\Index(name: 'idx_ticket_epic', columns: ['epic_key'])]
#[ORM\HasLifecycleCallbacks]
class JiraTicket
{
    use TimestampableTrait;

    /** Taille maximale conservée pour la description (diagnostic / aide à la rédaction). */
    public const MAX_DESCRIPTION_LENGTH = 20000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    #[ORM\ManyToOne(inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReleaseSection $section = null;

    #[ORM\Column(length: 50)]
    private string $jiraIssueId = '';

    #[ORM\Column(length: 50)]
    private string $jiraKey = '';

    #[ORM\Column(length: 100)]
    private string $issueType = '';

    #[ORM\Column(length: 500)]
    private string $summary = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $status = null;

    /** Catégorie de statut Jira : new | indeterminate | done. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $statusCategory = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $priority = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $epicKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $epicName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $parentKey = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $components = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $labels = [];

    /** @var list<array{id: string, name: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $fixVersions = [];

    #[ORM\Column(length: 500)]
    private string $jiraUrl = '';

    /** @var array<string, mixed>|null Sous-ensemble non sensible de la réponse Jira (diagnostic). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 30, enumType: TicketSyncState::class)]
    private TicketSyncState $syncState = TicketSyncState::Active;

    /** Ticket retiré volontairement de la release (non republié, non ré-ajouté par la synchro). */
    #[ORM\Column(options: ['default' => false])]
    private bool $excluded = false;

    /** Ajouté manuellement (hors JQL de la version). */
    #[ORM\Column(options: ['default' => false])]
    private bool $addedManually = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $jiraUpdatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

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

    public function getSection(): ?ReleaseSection
    {
        return $this->section;
    }

    public function setSection(?ReleaseSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getJiraIssueId(): string
    {
        return $this->jiraIssueId;
    }

    public function setJiraIssueId(string $jiraIssueId): static
    {
        $this->jiraIssueId = $jiraIssueId;

        return $this;
    }

    public function getJiraKey(): string
    {
        return $this->jiraKey;
    }

    public function setJiraKey(string $jiraKey): static
    {
        $this->jiraKey = $jiraKey;

        return $this;
    }

    public function getIssueType(): string
    {
        return $this->issueType;
    }

    public function setIssueType(string $issueType): static
    {
        $this->issueType = mb_substr($issueType, 0, 100);

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = mb_substr($summary, 0, 500);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description ? null : mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusCategory(): ?string
    {
        return $this->statusCategory;
    }

    public function setStatusCategory(?string $statusCategory): static
    {
        $this->statusCategory = $statusCategory;

        return $this;
    }

    public function isDone(): bool
    {
        return 'done' === $this->statusCategory;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(?string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getEpicKey(): ?string
    {
        return $this->epicKey;
    }

    public function setEpicKey(?string $epicKey): static
    {
        $this->epicKey = $epicKey;

        return $this;
    }

    public function getEpicName(): ?string
    {
        return $this->epicName;
    }

    public function setEpicName(?string $epicName): static
    {
        $this->epicName = null === $epicName ? null : mb_substr($epicName, 0, 255);

        return $this;
    }

    public function getParentKey(): ?string
    {
        return $this->parentKey;
    }

    public function setParentKey(?string $parentKey): static
    {
        $this->parentKey = $parentKey;

        return $this;
    }

    /** @return list<string> */
    public function getComponents(): array
    {
        return $this->components;
    }

    /** @param list<string> $components */
    public function setComponents(array $components): static
    {
        $this->components = array_values($components);

        return $this;
    }

    /** @return list<string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /** @param list<string> $labels */
    public function setLabels(array $labels): static
    {
        $this->labels = array_values($labels);

        return $this;
    }

    /** @return list<array{id: string, name: string}> */
    public function getFixVersions(): array
    {
        return $this->fixVersions;
    }

    /** @param list<array{id: string, name: string}> $fixVersions */
    public function setFixVersions(array $fixVersions): static
    {
        $this->fixVersions = array_values($fixVersions);

        return $this;
    }

    /** @return list<string> */
    public function getFixVersionNames(): array
    {
        return array_map(static fn (array $v) => $v['name'], $this->fixVersions);
    }

    public function getJiraUrl(): string
    {
        return $this->jiraUrl;
    }

    public function setJiraUrl(string $jiraUrl): static
    {
        $this->jiraUrl = $jiraUrl;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    /** @param array<string, mixed>|null $rawData */
    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getSyncState(): TicketSyncState
    {
        return $this->syncState;
    }

    public function setSyncState(TicketSyncState $syncState): static
    {
        $this->syncState = $syncState;

        return $this;
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }

    public function setExcluded(bool $excluded): static
    {
        $this->excluded = $excluded;

        return $this;
    }

    public function isAddedManually(): bool
    {
        return $this->addedManually;
    }

    public function setAddedManually(bool $addedManually): static
    {
        $this->addedManually = $addedManually;

        return $this;
    }

    public function getJiraUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->jiraUpdatedAt;
    }

    public function setJiraUpdatedAt(?\DateTimeImmutable $jiraUpdatedAt): static
    {
        $this->jiraUpdatedAt = $jiraUpdatedAt;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }
}
