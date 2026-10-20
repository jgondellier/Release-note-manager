<?php

declare(strict_types=1);

namespace App\Service\AcceptanceReport;

use App\Entity\AcceptanceReport;
use App\Entity\ExternalConnection;
use App\Entity\Project;
use App\Entity\Release;
use App\Enum\SectionCategory;
use App\Repository\AcceptanceReportRepository;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\JiraApi;
use App\Service\Jira\JiraCommentService;
use App\Service\Jira\JiraIssueService;
use App\Service\Jira\JiraJqlBuilder;
use App\Service\Jira\JiraVersionService;
use App\Service\Jira\OctaneCoverageService;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;
use App\Service\ReleaseNote\IssueTypeMappingResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PV de recette d'une version : création préremplie et instantané Jira.
 *
 * - Périmètre : tickets de la Fix Version hors anomalies, Epics et sous-tâches.
 * - Exécution des tests : commentaires de ces tickets commençant par le préfixe
 *   configuré (« TEST : »), regroupés par ticket.
 * - Anomalies : tickets de la version dont le type est rattaché à la catégorie
 *   « Correction » dans les correspondances de types (Bug, Bogue, Anomalie…), plus les
 *   anomalies liées (liens Jira) aux fiches du périmètre, même hors version.
 * - Réserves par défaut : liste des anomalies non terminées. Un texte de réserves
 *   modifié par l'utilisateur n'est jamais remplacé.
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
        private readonly OctaneCoverageService $coverageService,
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

            $anomalyItems = $this->anomalies($connection, $anomalies, $scope, $rules);
            $previousDefault = $report->getJiraSnapshot()['defaultReserves'] ?? null;
            $defaultReserves = self::defaultReserves($anomalyItems);
            // Réserves pré-remplies uniquement si vides ou encore égales au texte généré précédemment.
            if (null === $report->getReserves() || $report->getReserves() === $previousDefault) {
                $report->setReserves($defaultReserves);
            }

            $scopeItems = array_map($this->item(...), $scope);
            [$scopeItems, $coverage, $coverageError] = $this->coverage($project, $scope, $scopeItems);

            $snapshot = [
                'versionName' => $version->name,
                'jql' => JiraJqlBuilder::forFixVersion($project->getJiraProjectKey(), $version->id),
                'scope' => $scopeItems,
                'coverage' => $coverage,
                'coverageError' => $coverageError,
                'tests' => $tests,
                'anomalies' => $anomalyItems,
                'defaultReserves' => $defaultReserves,
            ];
            $report->recordJiraSnapshot($snapshot);
            $report->touch();
            $this->em->flush();

            return ['scope' => \count($scope), 'tests' => \count($tests), 'anomalies' => \count($anomalyItems)];
        } catch (ExternalApiException $e) {
            $report->recordJiraError($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    /**
     * Anomalies de la version + anomalies liées aux fiches du périmètre (dédoublonnées),
     * non terminées d'abord.
     *
     * @param list<JiraIssue> $versionAnomalies
     * @param list<JiraIssue> $scope
     *
     * @return list<array<string, mixed>>
     */
    private function anomalies(ExternalConnection $connection, array $versionAnomalies, array $scope, IssueTypeRuleSet $rules): array
    {
        $items = [];
        foreach ($versionAnomalies as $issue) {
            $items[$issue->key] = $this->item($issue) + ['origin' => 'version', 'linkedFrom' => []];
        }

        /** @var array<string, array{link: array<string, mixed>, from: list<string>}> $linked */
        $linked = [];
        foreach ($scope as $issue) {
            foreach ($issue->linkedIssues as $link) {
                if (SectionCategory::Fix !== $rules->match($link['type'])->category) {
                    continue;
                }
                $label = sprintf('%s (%s)', $issue->key, $link['link']);
                if (isset($items[$link['key']])) {
                    $items[$link['key']]['linkedFrom'][] = $label;
                    continue;
                }
                $linked[$link['key']]['link'] = $link;
                $linked[$link['key']]['from'][] = $label;
            }
        }

        if ([] !== $linked) {
            // Relecture des anomalies liées pour disposer de leurs données complètes (résolution, URL…).
            $full = $this->issueService->findByKeys($connection, array_keys($linked));
            foreach ($linked as $key => $data) {
                $issue = $full[$key] ?? null;
                $item = null !== $issue ? $this->item($issue) : [
                    'key' => $key,
                    'summary' => $data['link']['summary'],
                    'type' => $data['link']['type'],
                    'status' => $data['link']['status'],
                    'statusCategory' => $data['link']['statusCategory'],
                    'priority' => $data['link']['priority'],
                    'resolution' => null,
                    'url' => JiraApi::browseUrl($connection, $key),
                ];
                $items[$key] = $item + ['origin' => 'linked', 'linkedFrom' => $data['from']];
            }
        }

        $list = array_values($items);
        usort($list, static fn (array $a, array $b) => [('done' === $a['statusCategory']) ? 1 : 0, $a['key']] <=> [('done' === $b['statusCategory']) ? 1 : 0, $b['key']]);
        return array_map(static fn (array $item) => ['linkedFrom' => array_values(array_unique($item['linkedFrom']))] + $item, $list);
    }

    /**
     * Couverture de tests Octane de chaque fiche du périmètre (si activée sur le projet).
     * Une erreur n'interrompt pas l'actualisation : elle est mémorisée et affichée.
     *
     * @param list<JiraIssue>            $scope
     * @param list<array<string, mixed>> $items
     *
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>, 2: ?string}
     */
    private function coverage(Project $project, array $scope, array $items): array
    {
        if (!$project->isOctaneCoverageEnabled()) {
            return [$items, null, null];
        }
        $error = null;
        foreach ($scope as $i => $issue) {
            try {
                $items[$i]['coverage'] = $this->coverageService->getCoverage($project, $issue);
            } catch (ExternalApiException $e) {
                $items[$i]['coverage'] = null;
                $error ??= $e->isNotFound()
                    ? 'Extension Jira de couverture Octane introuvable (API /rest/octane-coverage absente).'
                    : $e->getMessage();
                if ($e->isNotFound() || ExternalApiException::AUTHENTICATION === $e->getCategory()) {
                    break; // inutile d'insister pour les fiches suivantes
                }
            }
        }

        return [$items, OctaneCoverageService::aggregate(array_values(array_filter(array_column($items, 'coverage')))), $error];
    }

    /** @param list<array<string, mixed>> $anomalies */
    public static function defaultReserves(array $anomalies): ?string
    {
        $open = array_filter($anomalies, static fn (array $a) => 'done' !== ($a['statusCategory'] ?? null));
        if ([] === $open) {
            return null;
        }
        $lines = ['Anomalies non terminées à la date du PV :'];
        foreach ($open as $a) {
            $lines[] = sprintf('- %s — %s (%s)', $a['key'], $a['summary'], $a['status'] ?? 'statut inconnu');
        }

        return implode("\n", $lines);
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
