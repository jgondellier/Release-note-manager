<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SectionCategory;
use App\Repository\LabelMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Table de conversion d'une étiquette Jira (mot-clé) en titre de section lisible.
 *
 * Les tickets sans Epic sont regroupés par étiquette ; l'ordre (position) départage
 * un ticket portant plusieurs étiquettes. Une étiquette « ignorée » ne crée jamais de section.
 * project = null : règle globale ; sinon règle du projet (prioritaire). Comparaison insensible à la casse.
 */
#[ORM\Entity(repositoryClass: LabelMappingRepository::class)]
#[ORM\Table(name: 'label_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_label_mapping_project_label', columns: ['project_id', 'label'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['project', 'label'], message: 'Une conversion existe déjà pour cette étiquette et ce périmètre.', ignoreNull: false)]
class LabelMapping
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** Étiquette Jira telle que saisie dans les tickets (mot-clé, sans espace). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Indiquez l\'étiquette Jira.')]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^\S+$/', message: 'Une étiquette Jira ne contient pas d\'espace.')]
    private string $label = '';

    /** Phrase lisible utilisée comme titre de section. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** Catégorie imposée (badge) ; à défaut, catégorie majoritaire des tickets. */
    #[ORM\Column(length: 20, nullable: true, enumType: SectionCategory::class)]
    private ?SectionCategory $category = null;

    /** Étiquette technique ignorée pour le regroupement (ex. « release »). */
    #[ORM\Column(options: ['default' => false])]
    private bool $ignored = false;

    #[ORM\Column(options: ['default' => 100])]
    #[Assert\Range(min: 0, max: 10000)]
    private int $position = 100;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->ignored && null === $this->title) {
            $context->buildViolation('Indiquez la phrase lisible (ou cochez « Ignorer »).')->atPath('title')->addViolation();
        }
    }

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = trim((string) $label);

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $title = null === $title ? null : trim($title);
        $this->title = '' === $title ? null : $title;

        return $this;
    }

    public function getCategory(): ?SectionCategory
    {
        return $this->category;
    }

    public function setCategory(?SectionCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    public function setIgnored(bool $ignored): static
    {
        $this->ignored = $ignored;

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
