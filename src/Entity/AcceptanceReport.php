<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AcceptanceDecision;
use App\Enum\AcceptanceDocumentStatus;
use App\Repository\AcceptanceReportRepository;
use App\Service\Jira\OctaneCoverageService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Procès-verbal de recette (tests système) d'une version.
 *
 * Les champs éditoriaux sont saisis par l'utilisateur. Les données Jira
 * (périmètre, exécution des tests, anomalies) sont figées dans un instantané
 * (jiraSnapshot) mis à jour à la demande : le PV publié reste stable et
 * vérifiable même si Jira évolue ensuite.
 */
#[ORM\Entity(repositoryClass: AcceptanceReportRepository::class)]
#[ORM\Table(name: 'acceptance_report')]
#[ORM\UniqueConstraint(name: 'uniq_acceptance_release', columns: ['release_id'])]
#[ORM\HasLifecycleCallbacks]
class AcceptanceReport
{
    use TimestampableTrait;

    public const DEFAULT_TEST_PREFIX = 'TEST :';
    public const DEFAULT_SCOPE_INTRO = "Les fiches présentées dans le paragraphe périmètre ont été traitées et testées individuellement.\n\nLes plans de test, les critères d'acceptation et les preuves de tests sont présentés dans les fiches Jira.";

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'acceptanceReport')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    /** Titre du document (ex. « Digital Courtage - Finagora - Gestion Clients »). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre du document est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $documentTitle = '';

    #[ORM\Column(length: 20, enumType: AcceptanceDocumentStatus::class)]
    private AcceptanceDocumentStatus $documentStatus = AcceptanceDocumentStatus::Draft;

    /** Projet / Application / Plan produit / Chantier. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le projet / application est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $applicationName = '';

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $code = null;

    /** Libellé de version affiché (ex. « Finagora - v 1.0.24 »). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La version est obligatoire.')]
    #[Assert\Length(max: 255)]
    private string $versionLabel = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mepDate = null;

    /** Objet de la recette (ex. « Évolutions relatives à la gestion client »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    /** Responsables de la recette (un par ligne). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $acceptanceManagers = null;

    /** Responsables de la livraison (un par ligne). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deliveryManagers = null;

    /**
     * Déroulement de la recette.
     *
     * @var list<array{phase: ?string, plannedStart: ?string, plannedEnd: ?string, actualStart: ?string, actualEnd: ?string, environment: ?string, comment: ?string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $phases = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scopeIntro = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $outOfScope = null;

    /** Préfixe identifiant les commentaires Jira de tests (ex. « TEST : »). */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Le préfixe des commentaires de tests est obligatoire.')]
    #[Assert\Length(max: 30)]
    private string $testCommentPrefix = self::DEFAULT_TEST_PREFIX;

    /** Commentaire de synthèse sur les anomalies (ex. « toutes avérées, corrigées et validées »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anomaliesComment = null;

    #[ORM\Column(length: 30, nullable: true, enumType: AcceptanceDecision::class)]
    private ?AcceptanceDecision $decision = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reserves = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $deliverySignatory = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliverySignedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $acceptanceSignatory = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptanceSignedAt = null;

    /**
     * Instantané Jira : scope (US), tests (commentaires par US), anomalies.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $jiraSnapshot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $jiraSyncedAt = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $jiraSyncError = null;

    #[Assert\Callback]
    public function validateDecision(ExecutionContextInterface $context): void
    {
        if (AcceptanceDecision::AcceptedWithReserves === $this->decision && null === $this->reserves) {
            $context->buildViolation('Précisez les réserves pour une recette acceptée avec réserves.')
                ->atPath('reserves')->addViolation();
        }
        if (AcceptanceDocumentStatus::Final === $this->documentStatus && null === $this->decision) {
            $context->buildViolation('Un PV définitif doit comporter une décision.')
                ->atPath('decision')->addViolation();
        }
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

    public function getDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    public function setDocumentTitle(?string $documentTitle): static
    {
        $this->documentTitle = trim((string) $documentTitle);

        return $this;
    }

    public function getDocumentStatus(): AcceptanceDocumentStatus
    {
        return $this->documentStatus;
    }

    public function setDocumentStatus(AcceptanceDocumentStatus $documentStatus): static
    {
        $this->documentStatus = $documentStatus;

        return $this;
    }

    public function getApplicationName(): string
    {
        return $this->applicationName;
    }

    public function setApplicationName(?string $applicationName): static
    {
        $this->applicationName = trim((string) $applicationName);

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = self::clean($code);

        return $this;
    }

    public function getVersionLabel(): string
    {
        return $this->versionLabel;
    }

    public function setVersionLabel(?string $versionLabel): static
    {
        $this->versionLabel = trim((string) $versionLabel);

        return $this;
    }

    public function getMepDate(): ?\DateTimeImmutable
    {
        return $this->mepDate;
    }

    public function setMepDate(?\DateTimeImmutable $mepDate): static
    {
        $this->mepDate = $mepDate;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = self::clean($subject);

        return $this;
    }

    public function getAcceptanceManagers(): ?string
    {
        return $this->acceptanceManagers;
    }

    public function setAcceptanceManagers(?string $acceptanceManagers): static
    {
        $this->acceptanceManagers = self::clean($acceptanceManagers);

        return $this;
    }

    public function getDeliveryManagers(): ?string
    {
        return $this->deliveryManagers;
    }

    public function setDeliveryManagers(?string $deliveryManagers): static
    {
        $this->deliveryManagers = self::clean($deliveryManagers);

        return $this;
    }

    /** @return list<string> */
    public function getAcceptanceManagerList(): array
    {
        return self::lines($this->acceptanceManagers);
    }

    /** @return list<string> */
    public function getDeliveryManagerList(): array
    {
        return self::lines($this->deliveryManagers);
    }

    /** @return list<array{phase: ?string, plannedStart: ?string, plannedEnd: ?string, actualStart: ?string, actualEnd: ?string, environment: ?string, comment: ?string}> */
    public function getPhases(): array
    {
        return $this->phases;
    }

    /** @param iterable<array<string, mixed>> $phases */
    public function setPhases(iterable $phases): static
    {
        $clean = [];
        foreach ($phases as $phase) {
            $row = [];
            foreach (['phase', 'plannedStart', 'plannedEnd', 'actualStart', 'actualEnd', 'environment', 'comment'] as $field) {
                $value = $phase[$field] ?? null;
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                $row[$field] = null === $value || '' === trim((string) $value) ? null : trim((string) $value);
            }
            if (array_filter($row, static fn ($v) => null !== $v) !== []) {
                $clean[] = $row;
            }
        }
        $this->phases = $clean;

        return $this;
    }

    public function getScopeIntro(): ?string
    {
        return $this->scopeIntro;
    }

    public function setScopeIntro(?string $scopeIntro): static
    {
        $this->scopeIntro = self::clean($scopeIntro);

        return $this;
    }

    public function getOutOfScope(): ?string
    {
        return $this->outOfScope;
    }

    public function setOutOfScope(?string $outOfScope): static
    {
        $this->outOfScope = self::clean($outOfScope);

        return $this;
    }

    public function getTestCommentPrefix(): string
    {
        return $this->testCommentPrefix;
    }

    public function setTestCommentPrefix(?string $testCommentPrefix): static
    {
        $this->testCommentPrefix = trim((string) $testCommentPrefix);

        return $this;
    }

    public function getAnomaliesComment(): ?string
    {
        return $this->anomaliesComment;
    }

    public function setAnomaliesComment(?string $anomaliesComment): static
    {
        $this->anomaliesComment = self::clean($anomaliesComment);

        return $this;
    }

    public function getDecision(): ?AcceptanceDecision
    {
        return $this->decision;
    }

    public function setDecision(?AcceptanceDecision $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function getReserves(): ?string
    {
        return $this->reserves;
    }

    public function setReserves(?string $reserves): static
    {
        $this->reserves = self::clean($reserves);

        return $this;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function setComments(?string $comments): static
    {
        $this->comments = self::clean($comments);

        return $this;
    }

    public function getDeliverySignatory(): ?string
    {
        return $this->deliverySignatory;
    }

    public function setDeliverySignatory(?string $deliverySignatory): static
    {
        $this->deliverySignatory = self::clean($deliverySignatory);

        return $this;
    }

    public function getDeliverySignedAt(): ?\DateTimeImmutable
    {
        return $this->deliverySignedAt;
    }

    public function setDeliverySignedAt(?\DateTimeImmutable $deliverySignedAt): static
    {
        $this->deliverySignedAt = $deliverySignedAt;

        return $this;
    }

    public function getAcceptanceSignatory(): ?string
    {
        return $this->acceptanceSignatory;
    }

    public function setAcceptanceSignatory(?string $acceptanceSignatory): static
    {
        $this->acceptanceSignatory = self::clean($acceptanceSignatory);

        return $this;
    }

    public function getAcceptanceSignedAt(): ?\DateTimeImmutable
    {
        return $this->acceptanceSignedAt;
    }

    public function setAcceptanceSignedAt(?\DateTimeImmutable $acceptanceSignedAt): static
    {
        $this->acceptanceSignedAt = $acceptanceSignedAt;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getJiraSnapshot(): ?array
    {
        return $this->jiraSnapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function recordJiraSnapshot(array $snapshot): static
    {
        $this->jiraSnapshot = $snapshot;
        $this->jiraSyncedAt = new \DateTimeImmutable();
        $this->jiraSyncError = null;

        return $this;
    }

    public function recordJiraError(string $message): static
    {
        $this->jiraSyncError = mb_substr($message, 0, 1000);

        return $this;
    }

    public function getJiraSyncedAt(): ?\DateTimeImmutable
    {
        return $this->jiraSyncedAt;
    }

    public function getJiraSyncError(): ?string
    {
        return $this->jiraSyncError;
    }

    /** @return list<array<string, mixed>> */
    public function getScopeItems(): array
    {
        // Identifiants de statut Octane normalisés, y compris pour les instantanés déjà enregistrés.
        return array_map(static function ($item) {
            if (\is_array($item) && \is_array($item['coverage'] ?? null)) {
                $item['coverage'] = OctaneCoverageService::canonicalize($item['coverage']);
            }

            return $item;
        }, array_values((array) ($this->jiraSnapshot['scope'] ?? [])));
    }

    /** @return array<string, mixed>|null couverture Octane cumulée (null si non activée) */
    public function getCoverageSummary(): ?array
    {
        $coverage = $this->jiraSnapshot['coverage'] ?? null;

        return \is_array($coverage) ? OctaneCoverageService::canonicalize($coverage) : null;
    }

    public function getCoverageError(): ?string
    {
        $error = $this->jiraSnapshot['coverageError'] ?? null;

        return \is_string($error) ? $error : null;
    }

    /** @return list<array<string, mixed>> */
    public function getTestItems(): array
    {
        return array_values((array) ($this->jiraSnapshot['tests'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function getAnomalyItems(): array
    {
        return array_values((array) ($this->jiraSnapshot['anomalies'] ?? []));
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = str_replace("\r\n", "\n", $value);

        return '' === trim($value) ? null : $value;
    }

    /** @return list<string> */
    private static function lines(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $value)), static fn ($l) => '' !== $l));
    }
}
