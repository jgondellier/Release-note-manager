<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\PublicationTarget;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Projet : périmètre applicatif d'une release note (clé Jira + espaces Confluence).
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\UniqueConstraint(name: 'uniq_project_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Ce code projet est déjà utilisé.')]
class Project
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom du projet est obligatoire.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le code est obligatoire.')]
    #[Assert\Length(max: 50)]
    #[Assert\Regex(pattern: '/^[A-Z0-9][A-Z0-9_-]*$/', message: 'Le code ne contient que des majuscules, chiffres, « _ » et « - ».')]
    private string $code = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'jira_connection_id', nullable: true, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Sélectionnez une connexion Jira.')]
    private ?ExternalConnection $jiraConnection = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La clé de projet Jira est obligatoire.')]
    #[Assert\Regex(pattern: '/^[A-Z][A-Z0-9_]{0,49}$/', message: 'Clé Jira invalide (ex. CCS, FIN).')]
    private string $jiraProjectKey = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'confluence_cloud_connection_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ExternalConnection $confluenceCloudConnection = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^~?[A-Za-z0-9_-]+$/', message: "Clé d'espace Confluence invalide.")]
    private ?string $confluenceCloudSpaceKey = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: "L'identifiant de page parente est numérique.")]
    private ?string $confluenceCloudParentPageId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'confluence_server_connection_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ExternalConnection $confluenceServerConnection = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^~?[A-Za-z0-9_-]+$/', message: "Clé d'espace Confluence invalide.")]
    private ?string $confluenceServerSpaceKey = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: "L'identifiant de page parente est numérique.")]
    private ?string $confluenceServerParentPageId = null;

    /** Page parente dédiée aux PV de recette (Cloud) ; à défaut, PV sous la page de regroupement de la version. */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: "L'identifiant de page parente est numérique.")]
    private ?string $confluenceCloudAcceptanceParentPageId = null;

    /** Page parente dédiée aux PV de recette (Server / Data Center). */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: "L'identifiant de page parente est numérique.")]
    private ?string $confluenceServerAcceptanceParentPageId = null;

    /** Couverture de tests Octane via l'extension Jira « Core Software Delivery Platform » (octane-coverage). */
    #[ORM\Column(options: ['default' => false])]
    private bool $octaneCoverageEnabled = false;

    /** Identifiant de configuration d'espace Octane (workspace-config-id) ; vide = découverte automatique. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9-]+$/', message: 'Identifiant de configuration invalide.')]
    private ?string $octaneWorkspaceConfigId = null;

    /** Identifiant d'espace de travail Octane (workspace-id) ; vide = découverte automatique. */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Regex(pattern: '/^\d+$/', message: "L'identifiant d'espace de travail Octane est numérique.")]
    private ?string $octaneWorkspaceId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    /** @var array<string, array{ok: bool, message: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastCheckResult = null;

    /** @var Collection<int, Release> */
    #[ORM\OneToMany(targetEntity: Release::class, mappedBy: 'project')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $releases;

    public function __construct()
    {
        $this->releases = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name, $this->code);
    }

    #[Assert\Callback]
    public function validateConnections(ExecutionContextInterface $context): void
    {
        if ((null === $this->octaneWorkspaceConfigId) !== (null === $this->octaneWorkspaceId)) {
            $context->buildViolation('Renseignez les deux identifiants Octane, ou aucun (découverte automatique).')
                ->atPath('octaneWorkspaceId')->addViolation();
        }
        if (null !== $this->jiraConnection && ConnectionProvider::Jira !== $this->jiraConnection->getProvider()) {
            $context->buildViolation("La connexion choisie n'est pas une connexion Jira.")
                ->atPath('jiraConnection')->addViolation();
        }

        $this->validateConfluence($context, $this->confluenceCloudConnection, $this->confluenceCloudSpaceKey, DeploymentType::Cloud, 'confluenceCloud');
        $this->validateConfluence($context, $this->confluenceServerConnection, $this->confluenceServerSpaceKey, DeploymentType::Server, 'confluenceServer');
    }

    private function validateConfluence(ExecutionContextInterface $context, ?ExternalConnection $connection, ?string $spaceKey, DeploymentType $expected, string $prefix): void
    {
        if (null === $connection) {
            if (null !== $spaceKey) {
                $context->buildViolation('Sélectionnez la connexion Confluence correspondant à cet espace.')
                    ->atPath($prefix . 'Connection')->addViolation();
            }

            return;
        }
        if (ConnectionProvider::Confluence !== $connection->getProvider() || $expected !== $connection->getDeploymentType()) {
            $context->buildViolation(sprintf('La connexion doit être de type Confluence %s.', $expected->label()))
                ->atPath($prefix . 'Connection')->addViolation();
        }
        if (null === $spaceKey) {
            $context->buildViolation("La clé d'espace est obligatoire lorsque la connexion est renseignée.")
                ->atPath($prefix . 'SpaceKey')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = strtoupper(trim((string) $code));

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::nullIfBlank($description);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getJiraConnection(): ?ExternalConnection
    {
        return $this->jiraConnection;
    }

    public function setJiraConnection(?ExternalConnection $jiraConnection): static
    {
        $this->jiraConnection = $jiraConnection;

        return $this;
    }

    public function getJiraProjectKey(): string
    {
        return $this->jiraProjectKey;
    }

    public function setJiraProjectKey(?string $jiraProjectKey): static
    {
        $this->jiraProjectKey = strtoupper(trim((string) $jiraProjectKey));

        return $this;
    }

    public function getConfluenceCloudConnection(): ?ExternalConnection
    {
        return $this->confluenceCloudConnection;
    }

    public function setConfluenceCloudConnection(?ExternalConnection $connection): static
    {
        $this->confluenceCloudConnection = $connection;

        return $this;
    }

    public function getConfluenceCloudSpaceKey(): ?string
    {
        return $this->confluenceCloudSpaceKey;
    }

    public function setConfluenceCloudSpaceKey(?string $spaceKey): static
    {
        $this->confluenceCloudSpaceKey = self::nullIfBlank($spaceKey);

        return $this;
    }

    public function getConfluenceCloudParentPageId(): ?string
    {
        return $this->confluenceCloudParentPageId;
    }

    public function setConfluenceCloudParentPageId(?string $pageId): static
    {
        $this->confluenceCloudParentPageId = self::nullIfBlank($pageId);

        return $this;
    }

    public function getConfluenceServerConnection(): ?ExternalConnection
    {
        return $this->confluenceServerConnection;
    }

    public function setConfluenceServerConnection(?ExternalConnection $connection): static
    {
        $this->confluenceServerConnection = $connection;

        return $this;
    }

    public function getConfluenceServerSpaceKey(): ?string
    {
        return $this->confluenceServerSpaceKey;
    }

    public function setConfluenceServerSpaceKey(?string $spaceKey): static
    {
        $this->confluenceServerSpaceKey = self::nullIfBlank($spaceKey);

        return $this;
    }

    public function getConfluenceServerParentPageId(): ?string
    {
        return $this->confluenceServerParentPageId;
    }

    public function setConfluenceServerParentPageId(?string $pageId): static
    {
        $this->confluenceServerParentPageId = self::nullIfBlank($pageId);

        return $this;
    }

    public function getConfluenceCloudAcceptanceParentPageId(): ?string
    {
        return $this->confluenceCloudAcceptanceParentPageId;
    }

    public function setConfluenceCloudAcceptanceParentPageId(?string $pageId): static
    {
        $this->confluenceCloudAcceptanceParentPageId = self::nullIfBlank($pageId);

        return $this;
    }

    public function getConfluenceServerAcceptanceParentPageId(): ?string
    {
        return $this->confluenceServerAcceptanceParentPageId;
    }

    public function setConfluenceServerAcceptanceParentPageId(?string $pageId): static
    {
        $this->confluenceServerAcceptanceParentPageId = self::nullIfBlank($pageId);

        return $this;
    }

    public function getConfluenceAcceptanceParentPageId(PublicationTarget $target): ?string
    {
        return PublicationTarget::Cloud === $target ? $this->confluenceCloudAcceptanceParentPageId : $this->confluenceServerAcceptanceParentPageId;
    }

    public function isOctaneCoverageEnabled(): bool
    {
        return $this->octaneCoverageEnabled;
    }

    public function setOctaneCoverageEnabled(bool $enabled): static
    {
        $this->octaneCoverageEnabled = $enabled;

        return $this;
    }

    public function getOctaneWorkspaceConfigId(): ?string
    {
        return $this->octaneWorkspaceConfigId;
    }

    public function setOctaneWorkspaceConfigId(?string $id): static
    {
        $this->octaneWorkspaceConfigId = self::nullIfBlank($id);

        return $this;
    }

    public function getOctaneWorkspaceId(): ?string
    {
        return $this->octaneWorkspaceId;
    }

    public function setOctaneWorkspaceId(?string $id): static
    {
        $this->octaneWorkspaceId = self::nullIfBlank($id);

        return $this;
    }

    public function getConfluenceConnection(PublicationTarget $target): ?ExternalConnection
    {
        return PublicationTarget::Cloud === $target ? $this->confluenceCloudConnection : $this->confluenceServerConnection;
    }

    public function getConfluenceSpaceKey(PublicationTarget $target): ?string
    {
        return PublicationTarget::Cloud === $target ? $this->confluenceCloudSpaceKey : $this->confluenceServerSpaceKey;
    }

    public function getConfluenceParentPageId(PublicationTarget $target): ?string
    {
        return PublicationTarget::Cloud === $target ? $this->confluenceCloudParentPageId : $this->confluenceServerParentPageId;
    }

    public function isTargetConfigured(PublicationTarget $target): bool
    {
        return null !== $this->getConfluenceConnection($target) && null !== $this->getConfluenceSpaceKey($target);
    }

    /** @return list<PublicationTarget> */
    public function getConfiguredTargets(): array
    {
        return array_values(array_filter(PublicationTarget::cases(), fn (PublicationTarget $t) => $this->isTargetConfigured($t)));
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    /** @return array<string, array{ok: bool, message: string}>|null */
    public function getLastCheckResult(): ?array
    {
        return $this->lastCheckResult;
    }

    public function hasConfigurationErrors(): bool
    {
        foreach ($this->lastCheckResult ?? [] as $check) {
            if (!$check['ok']) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array{ok: bool, message: string}> $result */
    public function recordCheckResult(array $result): static
    {
        $this->lastCheckResult = $result;
        $this->lastCheckedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, Release> */
    public function getReleases(): Collection
    {
        return $this->releases;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
