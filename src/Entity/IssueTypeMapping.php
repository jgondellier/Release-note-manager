<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SectionCategory;
use App\Repository\IssueTypeMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Correspondance « type de ticket Jira » → titre de section, pour les tickets sans Epic.
 *
 * - project = null : règle globale ; sinon règle spécifique au projet (prioritaire).
 * - jiraIssueType = « * » : section par défaut pour les types non mappés.
 * La comparaison des types est insensible à la casse.
 */
#[ORM\Entity(repositoryClass: IssueTypeMappingRepository::class)]
#[ORM\Table(name: 'issue_type_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_mapping_project_type', columns: ['project_id', 'jira_issue_type'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['project', 'jiraIssueType'], message: 'Une correspondance existe déjà pour ce type et ce périmètre.', ignoreNull: false)]
class IssueTypeMapping
{
    use TimestampableTrait;

    public const DEFAULT_TYPE = '*';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez le type Jira (ou « * » pour le défaut).')]
    #[Assert\Length(max: 100)]
    private string $jiraIssueType = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez le titre de section.')]
    #[Assert\Length(max: 150)]
    private string $sectionTitle = '';

    #[ORM\Column(length: 20, enumType: SectionCategory::class)]
    private SectionCategory $category = SectionCategory::Other;

    #[ORM\Column(options: ['default' => 100])]
    #[Assert\Range(min: 0, max: 10000)]
    private int $position = 100;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getJiraIssueType(): string
    {
        return $this->jiraIssueType;
    }

    public function setJiraIssueType(?string $jiraIssueType): static
    {
        $this->jiraIssueType = trim((string) $jiraIssueType);

        return $this;
    }

    public function isDefault(): bool
    {
        return self::DEFAULT_TYPE === $this->jiraIssueType;
    }

    public function getSectionTitle(): string
    {
        return $this->sectionTitle;
    }

    public function setSectionTitle(?string $sectionTitle): static
    {
        $this->sectionTitle = trim((string) $sectionTitle);

        return $this;
    }

    public function getCategory(): SectionCategory
    {
        return $this->category;
    }

    public function setCategory(SectionCategory $category): static
    {
        $this->category = $category;

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
}
