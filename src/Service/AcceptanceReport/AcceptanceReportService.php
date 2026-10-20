<?php

declare(strict_types=1);

namespace App\Service\AcceptanceReport;

use App\Entity\AcceptanceReport;
use App\Entity\Release;
use App\Enum\SectionCategory;
use App\Repository\AcceptanceReportRepository;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\JiraCommentService;
use App\Service\Jira\JiraIssueService;
use App\Service\Jira\JiraJqlBuilder;
use App\Service\Jira\JiraVersionService;
use App\Service\ReleaseNote\IssueTypeMappingResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PV de recette d'une version : création préremplie et instantané Jira.
 *
 * - Périmètre : tickets de la Fix Version hors anomalies, Epics et sous-tâches.
 * - Exécution des tests : commentaires de ces tickets commençant par le préfixe
 *   configuré (« TEST : »), regroupés par ticket.
 * - Anomalies : tickets de la version dont le type est rattaché à la catégorie
 *   « Correction » dans les correspondances de types (Bug, Bogue, Anomalie…).
 */
class AcceptanceReportService
{
    public function __construct(
        private readonly AcceptanceReportRepository $repository,
        private readonly JiraVersionService $versionService,
        private readonly JiraIssueService $issueService,
        private readonly JiraCommentService $commentService,
        private readonly IssueTypeMappingResolver $mappingResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Crée le PV de la release, prérempli depuis le dernier PV du projet (ou des valeurs par défaut). */
    public function create(Release $release): AcceptanceReport
    {
        if (null !== $release->getAcceptanceReport()) {
            return $release->getAcceptanceReport();
        }
        $project = $release->getProject();
        \assert(null !== $project);

        $report = new AcceptanceReport();
        $template = $this->repository->findLatestForProject($project);
        if (null !== $template) {
            $report->setDocumentTitle($template->getDocumentTitle())
                ->setApplicationName($template->getApplicationName())
                ->setCode($template->getCode())
                ->setAcceptanceManagers($template->getAcceptanceManagers())
                ->setDeliveryManagers($template->getDeliveryManagers())
                ->setScopeIntro($template->getScopeIntro())
                ->setOutOfScope($template->getOutOfScope())
                ->setTestCommentPrefix($template->getTestCommentPrefix())
                ->setDeliverySignatory($template->getDeliverySignatory())
                ->setAcceptanceSignatory($template->getAcceptanceSignatory())
                // Phases reprises sans les dates (propres à chaque version).
                ->setPhases(array_map(static fn (array $p) => ['phase' => $p['phase'] ?? null, 'environment' => $p['environment'] ?? null], $template->getPhases()));
        } else {
            $report->setDocumentTitle($project->getName())
                ->setApplicationName($project->getName() . ' - PV de Tests Système')
                ->setCode($project->getCode())
                ->setScopeIntro(AcceptanceReport::DEFAULT_SCOPE_INTRO)
                ->setOutOfScope('N/A')
                ->setPhases([
                    ['phase' => 'Tests système'],
                    ['phase' => 'Tests de non régression et validation'],
                ]);
        }
        $report->setVersionLabel(sprintf('%s - v %s', $project->getName(), $release->getJiraVersionName()))
            ->setMepDate($release->getReleaseDate());

        $release->setAcceptanceReport($report);
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    /**
     * Met à jour l'instantané Jira du PV (aucun champ éditorial n'est modifié).
     *
     * @return array{scope: int, tests: int, anomalies: int}
     */
    public function refreshFromJira(AcceptanceReport $report): array
    {
        $release = $report->getRelease();
        $project = $release?->getProject();
        $connection = $project?->getJiraConnection();
        try {
            if (null === $release || null === $project || null === $connection) {
                throw ExternalApiException::configuration('Le projet n\'a pas de connexion Jira.');
            }
            $version = $this->versionService->getVersion($project, $release->getJiraVersionId());
            $issues = $this->issueService->findByFixVersion($project, $version);
            $rules = $this->mappingResolver->forProject($project);

            $scope = [];
            $anomalies = [];
            foreach ($issues as $issue) {
                if ($issue->isEpic) {
                    continue;
                }
                if (SectionCategory::Fix === $rules->match($issue->issueType)->category) {
                    $anomalies[] = $issue;
                } elseif (!$issue->isSubtask) {
                    $scope[] = $issue;
                }
            }
            $byKey = static fn (JiraIssue $a, JiraIssue $b) => strnatcmp($a->key, $b->key);
            usort($scope, $byKey);
            usort($anomalies, $byKey);

            $tests = [];
            foreach ($scope as $issue) {
                $comments = JiraCommentService::filterByPrefix($this->commentService->getComments($connection, $issue->key), $report->getTestCommentPrefix());
                if ([] === $comments) {
                    continue;
                }
                $tests[] = $this->item($issue) + ['comments' => array_map(static fn ($c) => [
                    'author' => $c->author,
                    'date' => $c->createdAt?->format(\DateTimeInterface::ATOM),
                    'text' => $c->body,
                ], $comments)];
            }

            $snapshot = [
                'versionName' => $version->name,
                'jql' => JiraJqlBuilder::forFixVersion($project->getJiraProjectKey(), $version->id),
                'scope' => array_map($this->item(...), $scope),
                'tests' => $tests,
                'anomalies' => array_map($this->item(...), $anomalies),
            ];
            $report->recordJiraSnapshot($snapshot);
            $report->touch();
            $this->em->flush();

            return ['scope' => \count($scope), 'tests' => \count($tests), 'anomalies' => \count($anomalies)];
        } catch (ExternalApiException $e) {
            $report->recordJiraError($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function item(JiraIssue $issue): array
    {
        return [
            'key' => $issue->key,
            'summary' => $issue->summary,
            'type' => $issue->issueType,
            'status' => $issue->status,
            'statusCategory' => $issue->statusCategory,
            'priority' => $issue->priority,
            'resolution' => $issue->diagnostic['resolution'] ?? null,
            'url' => $issue->url,
        ];
    }
}
