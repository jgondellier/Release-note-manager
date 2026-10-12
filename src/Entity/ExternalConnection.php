<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\ConnectionTestStatus;
use App\Enum\DeploymentType;
use App\Repository\ExternalConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Connexion à une instance Jira ou Confluence (Cloud ou Server/Data Center).
 *
 * Le secret (API token, PAT ou mot de passe) est stocké UNIQUEMENT chiffré
 * (colonne encrypted_secret) par le SecretEncryptionService. Il n'est jamais
 * exposé par un getter « en clair » ni renvoyé au navigateur.
 */
#[ORM\Entity(repositoryClass: ExternalConnectionRepository::class)]
#[ORM\Table(name: 'external_connection')]
#[ORM\Index(name: 'idx_connection_provider_type', columns: ['provider', 'deployment_type'])]
#[ORM\UniqueConstraint(name: 'uniq_connection_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'Une connexion porte déjà ce nom.')]
class ExternalConnection
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: ConnectionProvider::class)]
    #[Assert\NotNull(message: 'Choisissez Jira ou Confluence.')]
    private ?ConnectionProvider $provider = null;

    #[ORM\Column(name: 'deployment_type', length: 20, enumType: DeploymentType::class)]
    #[Assert\NotNull(message: "Choisissez le type d'instance.")]
    private ?DeploymentType $deploymentType = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "L'URL de base est obligatoire.")]
    #[Assert\Url(message: "L'URL de base n'est pas valide.", protocols: ['https', 'http'], requireTld: false)]
    #[Assert\Length(max: 255)]
    private string $baseUrl = '';

    #[ORM\Column(length: 20, enumType: AuthenticationType::class)]
    #[Assert\NotNull(message: "Choisissez la méthode d'authentification.")]
    private ?AuthenticationType $authenticationType = null;

    /** Identifiant ou adresse e-mail (Basic). Inutile en Bearer. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $username = null;

    /** Secret chiffré (format « v1:base64(nonce|ciphertext) »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedSecret = null;

    /**
     * Jira uniquement : identifiant du champ « Epic Link » (ex. customfield_10014)
     * lorsque l'instance n'expose pas la relation parent pour les Epics.
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Regex(pattern: '/^customfield_\d+$/', message: 'Format attendu : customfield_12345.')]
    private ?string $jiraEpicLinkField = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastTestedAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ConnectionTestStatus::class)]
    private ?ConnectionTestStatus $lastTestStatus = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $lastTestMessage = null;

    public function __toString(): string
    {
        return $this->name;
    }

    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (AuthenticationType::Basic === $this->authenticationType && (null === $this->username || '' === trim($this->username))) {
            $context->buildViolation("L'identifiant (ou l'e-mail pour Cloud) est obligatoire en authentification Basic.")
                ->atPath('username')->addViolation();
        }
        if (DeploymentType::Cloud === $this->deploymentType && AuthenticationType::Bearer === $this->authenticationType) {
            $context->buildViolation('Atlassian Cloud requiert l\'authentification Basic (e-mail + API token).')
                ->atPath('authenticationType')->addViolation();
        }
        if (ConnectionProvider::Confluence === $this->provider && null !== $this->jiraEpicLinkField) {
            $context->buildViolation('Le champ Epic Link ne concerne que les connexions Jira.')
                ->atPath('jiraEpicLinkField')->addViolation();
        }
        if ('' !== $this->baseUrl && str_starts_with(strtolower($this->baseUrl), 'http://') && DeploymentType::Cloud === $this->deploymentType) {
            $context->buildViolation('Les instances Cloud sont accessibles uniquement en HTTPS.')
                ->atPath('baseUrl')->addViolation();
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

    public function getProvider(): ?ConnectionProvider
    {
        return $this->provider;
    }

    public function setProvider(?ConnectionProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getDeploymentType(): ?DeploymentType
    {
        return $this->deploymentType;
    }

    public function setDeploymentType(?DeploymentType $deploymentType): static
    {
        $this->deploymentType = $deploymentType;

        return $this;
    }

    public function isCloud(): bool
    {
        return DeploymentType::Cloud === $this->deploymentType;
    }

    public function isJira(): bool
    {
        return ConnectionProvider::Jira === $this->provider;
    }

    public function isConfluence(): bool
    {
        return ConnectionProvider::Confluence === $this->provider;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): static
    {
        $this->baseUrl = rtrim(trim((string) $baseUrl), '/');

        return $this;
    }

    public function getAuthenticationType(): ?AuthenticationType
    {
        return $this->authenticationType;
    }

    public function setAuthenticationType(?AuthenticationType $authenticationType): static
    {
        $this->authenticationType = $authenticationType;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $username = null === $username ? null : trim($username);
        $this->username = '' === $username ? null : $username;

        return $this;
    }

    public function getEncryptedSecret(): ?string
    {
        return $this->encryptedSecret;
    }

    public function setEncryptedSecret(?string $encryptedSecret): static
    {
        $this->encryptedSecret = $encryptedSecret;

        return $this;
    }

    public function hasSecret(): bool
    {
        return null !== $this->encryptedSecret && '' !== $this->encryptedSecret;
    }

    public function getJiraEpicLinkField(): ?string
    {
        return $this->jiraEpicLinkField;
    }

    public function setJiraEpicLinkField(?string $jiraEpicLinkField): static
    {
        $jiraEpicLinkField = null === $jiraEpicLinkField ? null : trim($jiraEpicLinkField);
        $this->jiraEpicLinkField = '' === $jiraEpicLinkField ? null : $jiraEpicLinkField;

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

    public function getLastTestedAt(): ?\DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    public function getLastTestStatus(): ?ConnectionTestStatus
    {
        return $this->lastTestStatus;
    }

    public function getLastTestMessage(): ?string
    {
        return $this->lastTestMessage;
    }

    public function recordTestResult(ConnectionTestStatus $status, string $message): static
    {
        $this->lastTestedAt = new \DateTimeImmutable();
        $this->lastTestStatus = $status;
        $this->lastTestMessage = mb_substr($message, 0, 1000);

        return $this;
    }

    /** Réinitialise le résultat de test lorsque des paramètres d'accès changent. */
    public function resetTestResult(): static
    {
        $this->lastTestedAt = null;
        $this->lastTestStatus = null;
        $this->lastTestMessage = null;

        return $this;
    }

    public function getDisplayType(): string
    {
        return sprintf('%s %s', $this->provider?->label() ?? '?', $this->deploymentType?->label() ?? '?');
    }
}
