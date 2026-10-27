<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationFormat;
use App\Enum\SectionCategory;
use App\Enum\SectionSourceType;
use App\Repository\ReleaseSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Section éditoriale d'une release note.
 *
 * Le titre initial vient de l'Epic ou de la correspondance de type, mais tous
 * les champs éditoriaux appartiennent ensuite à l'utilisateur : la
 * synchronisation Jira ne les modifie jamais.
 */
#[ORM\Entity(repositoryClass: ReleaseSectionRepository::class)]
#[ORM\Table(name: 'release_section')]
#[ORM\Index(name: 'idx_section_release_position', columns: ['release_id', 'position'])]
#[ORM\Index(name: 'idx_section_source', columns: ['release_id', 'source_type', 'source_key'])]
#[ORM\HasLifecycleCallbacks]
class ReleaseSection
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    #[ORM\Column(length: 20, enumType: SectionSourceType::class)]
    private SectionSourceType $sourceType = SectionSourceType::Manual;

    /** Clé de l'Epic (source epic) ou nom du type Jira (source issue_type). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sourceKey = null;

    #[ORM\Column(length: 20, enumType: SectionCategory::class)]
    private SectionCategory $category = SectionCategory::Other;

    /** Titre principal (format IT, et valeur par défaut des autres formats). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre de la section est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** Descriptif commun de la section. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Contenu technique (format IT). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $itContent = null;

    /** Risques et points de vigilance (format IT, jamais générés). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $risks = null;

    /** Tests et validations réalisés (format IT, jamais générés). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $validation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $functionalTitle = null;

    /** Wording fonctionnel (parcours, règles de gestion…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $functionalContent = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $communicationTitle = null;

    /** Wording de communication (bénéfices utilisateurs). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $communicationContent = null;

    /** Impact utilisateur / métier. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $impact = null;

    /** Action éventuelle à effectuer. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionRequired = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $audience = null;

    /** Liens utiles de la section (« Libellé | URL » par ligne). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $links = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleIt = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleFunctional = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleCommunication = true;

    /** Afficher la liste (sans clés Jira) des tickets dans le format fonctionnel. */
    #[ORM\Column(options: ['default' => false])]
    private bool $functionalShowTickets = false;

    /**
     * Confirmation manuelle de livraison. Sans elle, une section n'est présentée
     * comme « disponible » en communication que si tous ses tickets sont terminés.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $deliveryConfirmed = false;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, JiraTicket> */
    #[ORM\OneToMany(targetEntity: JiraTicket::class, mappedBy: 'section')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tickets;

    public function __construct()
    {
        $this->tickets = new ArrayCollection();
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

    public function getSourceType(): SectionSourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(SectionSourceType $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceKey(): ?string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(?string $sourceKey): static
    {
        $this->sourceKey = $sourceKey;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::clean($description);

        return $this;
    }

    public function getItContent(): ?string
    {
        return $this->itContent;
    }

    public function setItContent(?string $itContent): static
    {
        $this->itContent = self::clean($itContent);

        return $this;
    }

    public function getRisks(): ?string
    {
        return $this->risks;
    }

    public function setRisks(?string $risks): static
    {
        $this->risks = self::clean($risks);

        return $this;
    }

    public function getValidation(): ?string
    {
        return $this->validation;
    }

    public function setValidation(?string $validation): static
    {
        $this->validation = self::clean($validation);

        return $this;
    }

    public function getFunctionalTitle(): ?string
    {
        return $this->functionalTitle;
    }

    public function setFunctionalTitle(?string $functionalTitle): static
    {
        $this->functionalTitle = self::clean($functionalTitle);

        return $this;
    }

    public function getFunctionalContent(): ?string
    {
        return $this->functionalContent;
    }

    public function setFunctionalContent(?string $functionalContent): static
    {
        $this->functionalContent = self::clean($functionalContent);

        return $this;
    }

    public function getCommunicationTitle(): ?string
    {
        return $this->communicationTitle;
    }

    public function setCommunicationTitle(?string $communicationTitle): static
    {
        $this->communicationTitle = self::clean($communicationTitle);

        return $this;
    }

    public function getCommunicationContent(): ?string
    {
        return $this->communicationContent;
    }

    public function setCommunicationContent(?string $communicationContent): static
    {
        $this->communicationContent = self::clean($communicationContent);

        return $this;
    }

    public function getImpact(): ?string
    {
        return $this->impact;
    }

    public function setImpact(?string $impact): static
    {
        $this->impact = self::clean($impact);

        return $this;
    }

    public function getActionRequired(): ?string
    {
        return $this->actionRequired;
    }

    public function setActionRequired(?string $actionRequired): static
    {
        $this->actionRequired = self::clean($actionRequired);

        return $this;
    }

    public function getAudience(): ?string
    {
        return $this->audience;
    }

    public function setAudience(?string $audience): static
    {
        $this->audience = self::clean($audience);

        return $this;
    }

    public function getLinks(): ?string
    {
        return $this->links;
    }

    public function setLinks(?string $links): static
    {
        $this->links = self::clean($links);

        return $this;
    }

    public function isVisibleIt(): bool
    {
        return $this->visibleIt;
    }

    public function setVisibleIt(bool $visible): static
    {
        $this->visibleIt = $visible;

        return $this;
    }

    public function isVisibleFunctional(): bool
    {
        return $this->visibleFunctional;
    }

    public function setVisibleFunctional(bool $visible): static
    {
        $this->visibleFunctional = $visible;

        return $this;
    }

    public function isVisibleCommunication(): bool
    {
        return $this->visibleCommunication;
    }

    public function setVisibleCommunication(bool $visible): static
    {
        $this->visibleCommunication = $visible;

        return $this;
    }

    public function isVisibleIn(PublicationFormat $format): bool
    {
        return match ($format) {
            PublicationFormat::It => $this->visibleIt,
            PublicationFormat::Functional => $this->visibleFunctional,
            PublicationFormat::Communication => $this->visibleCommunication,
            PublicationFormat::AcceptanceReport => false,
        };
    }

    public function isFunctionalShowTickets(): bool
    {
        return $this->functionalShowTickets;
    }

    public function setFunctionalShowTickets(bool $show): static
    {
        $this->functionalShowTickets = $show;

        return $this;
    }

    public function isDeliveryConfirmed(): bool
    {
        return $this->deliveryConfirmed;
    }

    public function setDeliveryConfirmed(bool $confirmed): static
    {
        $this->deliveryConfirmed = $confirmed;

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

    /** @return Collection<int, JiraTicket> */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }

    /** @return list<JiraTicket> Tickets publiables (non exclus). */
    public function getActiveTickets(): array
    {
        return array_values($this->tickets->filter(static fn (JiraTicket $t) => !$t->isExcluded())->toArray());
    }

    public function addTicket(JiraTicket $ticket): static
    {
        if (!$this->tickets->contains($ticket)) {
            $this->tickets->add($ticket);
        }
        $ticket->setSection($this);

        return $this;
    }

    public function removeTicket(JiraTicket $ticket): static
    {
        $this->tickets->removeElement($ticket);
        if ($ticket->getSection() === $this) {
            $ticket->setSection(null);
        }

        return $this;
    }

    public function getNextTicketPosition(): int
    {
        $max = 0;
        foreach ($this->tickets as $ticket) {
            $max = max($max, $ticket->getPosition());
        }

        return $max + 10;
    }

    /**
     * Livraison confirmée : confirmation manuelle, ou tous les tickets actifs
     * sont dans la catégorie de statut Jira « done ».
     */
    public function isDelivered(): bool
    {
        if ($this->deliveryConfirmed) {
            return true;
        }
        $tickets = $this->getActiveTickets();
        if ([] === $tickets) {
            return false;
        }
        foreach ($tickets as $ticket) {
            if (!$ticket->isDone()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Visibilité initiale selon la catégorie : les sections techniques ne sont
     * proposées qu'au format IT. Appelée uniquement à la création de la section.
     */
    public function applyDefaultVisibility(): static
    {
        $technical = SectionCategory::Technical === $this->category;
        $this->visibleIt = true;
        $this->visibleFunctional = !$technical;
        $this->visibleCommunication = !$technical;

        return $this;
    }

    public function getTitleFor(PublicationFormat $format): string
    {
        return match ($format) {
            PublicationFormat::It, PublicationFormat::AcceptanceReport => $this->title,
            PublicationFormat::Functional => $this->functionalTitle ?? $this->title,
            PublicationFormat::Communication => $this->communicationTitle ?? $this->functionalTitle ?? $this->title,
        };
    }

    /** Vrai si un texte a été rédigé dans l'un des formats (le titre seul ne compte pas). */
    public function hasEditorialContent(): bool
    {
        foreach ([$this->description, $this->itContent, $this->risks, $this->validation, $this->functionalTitle, $this->functionalContent,
            $this->communicationTitle, $this->communicationContent, $this->impact, $this->actionRequired, $this->audience, $this->links] as $value) {
            if (null !== $value && '' !== trim($value)) {
                return true;
            }
        }

        return false;
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = str_replace("\r\n", "\n", $value);

        return '' === trim($value) ? null : $value;
    }
}
