<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\ReleaseStatus;
use App\Repository\ReleaseRepository;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraVersion;
use App\Service\Jira\JiraEpicResolver;
use App\Service\Jira\JiraIssueService;
use App\Service\Jira\JiraSnapshotStore;
use App\Service\Jira\JiraVersionService;
use App\Service\ReleaseNote\Dto\ImportPreview;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création d'une release note à partir d'une Fix Version Jira :
 * lecture des tickets de LA version choisie, résolution des Epics,
 * construction des sections initiales, enregistrement en brouillon.
 */
class ReleaseImportService
{
    public function __construct(
        private readonly JiraVersionService $versionService,
        private readonly JiraIssueService $issueService,
        private readonly JiraEpicResolver $epicResolver,
        private readonly IssueTypeMappingResolver $mappingResolver,
        private readonly SectionBuilder $sectionBuilder,
        private readonly JiraTicketMapper $ticketMapper,
        private readonly ReleaseRepository $releaseRepository,
        private readonly EntityManagerInterface $em,
        private readonly JiraSnapshotStore $snapshots,
    ) {
    }

    /**
     * Lecture Jira sans écriture : version, tickets et sections proposées.
     *
     * @param bool $remember mémorise les données lues pour que l'import confirmé ne relise pas Jira
     */
    public function preview(Project $project, string $versionId, bool $remember = false): ImportPreview
    {
        $this->assertImportable($project);
        $preview = $this->buildPreview($project, $this->versionService->getVersion($project, $versionId));
        if ($remember) {
            $this->snapshots->save(self::snapshotKey($project, $versionId), [$preview->version, $preview->issues, $preview->epics]);
        }

        return $preview;
    }

    /**
     * @param bool $reuseRemembered réutilise les données de l'aperçu affiché (si encore disponibles)
     *
     * @throws ReleaseAlreadyExistsException
     * @throws ExternalApiException
     */
    public function import(Project $project, string $versionId, ?string $title = null, bool $reuseRemembered = false): Release
    {
        $this->assertImportable($project);
        if (null !== $existing = $this->releaseRepository->findOneByProjectAndVersion($project, $versionId)) {
            throw new ReleaseAlreadyExistsException($existing);
        }

        $snapshot = $reuseRemembered ? $this->snapshots->take(self::snapshotKey($project, $versionId)) : null;
        if (\is_array($snapshot) && 3 === \count($snapshot)) {
            [$version, $issues, $epics] = $snapshot;
            $preview = new ImportPreview($version, $issues, $epics, $this->sectionBuilder->build($issues, $epics, $this->mappingResolver->forProject($project)));
        } else {
            $version = $this->versionService->getVersion($project, $versionId);
            $preview = $this->buildPreview($project, $version);
        }

        $release = (new Release())
            ->setProject($project)
            ->setJiraVersionId($version->id)
            ->setJiraVersionName($version->name)
            ->setJiraVersionDescription($version->description)
            ->setJiraVersionReleased($version->released)
            ->setJiraVersionReleaseDate($version->releaseDate)
            ->setReleaseDate($version->releaseDate)
            ->setStatus(ReleaseStatus::Draft)
            ->setTitle(null !== $title && '' !== trim($title) ? $title : sprintf('%s — version %s', $project->getName(), $version->name));

        $this->populate($release, $preview);
        $release->recordSync([
            'type' => 'import',
            'added' => $preview->issueCount(),
            'updated' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'inaccessible' => 0,
            'sections_created' => \count($preview->plans),
        ]);

        $this->em->persist($release);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Import concurrent de la même version.
            $this->em->clear();
            $existing = $this->releaseRepository->findOneByProjectAndVersion($project, $versionId);
            if (null !== $existing) {
                throw new ReleaseAlreadyExistsException($existing);
            }
            throw new \RuntimeException('Import impossible : contrainte d\'unicité violée.');
        }

        return $release;
    }

    /**
     * Ajoute à une release (vide ou non) les sections et tickets d'un aperçu.
     * Utilisé par l'import initial et la duplication.
     */
    public function populate(Release $release, ImportPreview $preview): void
    {
        $issuesByKey = [];
        foreach ($preview->issues as $issue) {
            $issuesByKey[$issue->key] = $issue;
        }

        $position = $release->getNextSectionPosition();
        foreach ($preview->plans as $plan) {
            $section = (new ReleaseSection())
                ->setSourceType($plan->sourceType)
                ->setSourceKey($plan->sourceKey)
                ->setCategory($plan->category)
                ->setTitle($plan->title)
                ->setPosition($position)
                ->applyDefaultVisibility();
            $position += 10;
            $release->addSection($section);

            $ticketPosition = 10;
            foreach ($plan->issueKeys as $key) {
                $issue = $issuesByKey[$key] ?? null;
                if (null === $issue || null !== $release->findTicketByIssueId($issue->id)) {
                    continue;
                }
                $ticket = new JiraTicket();
                $this->ticketMapper->apply($ticket, $issue, $preview->epics[$key] ?? null);
                $ticket->setPosition($ticketPosition);
                $ticketPosition += 10;
                $release->addTicket($ticket);
                $section->addTicket($ticket);
            }
        }
    }

    private function buildPreview(Project $project, JiraVersion $version): ImportPreview
    {
        $connection = $project->getJiraConnection();
        \assert(null !== $connection);
        $issues = $this->issueService->findByFixVersion($project, $version);
        $epics = $this->epicResolver->resolve($connection, $issues);
        $plans = $this->sectionBuilder->build($issues, $epics, $this->mappingResolver->forProject($project));

        return new ImportPreview($version, $issues, $epics, $plans);
    }

    private static function snapshotKey(Project $project, string $versionId): string
    {
        return sprintf('import|%d|%d|%s|%s', $project->getId(), $project->getJiraConnection()?->getId(), $project->getJiraProjectKey(), $versionId);
    }

    private function assertImportable(Project $project): void
    {
        if (!$project->isActive()) {
            throw ExternalApiException::configuration(sprintf('Le projet « %s » est inactif.', $project->getName()));
        }
        if (null === $project->getJiraConnection() || '' === $project->getJiraProjectKey()) {
            throw ExternalApiException::configuration(sprintf('Le projet « %s » n\'a pas de connexion ou de clé Jira.', $project->getName()));
        }
    }
}
