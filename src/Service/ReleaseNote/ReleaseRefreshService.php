<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\SectionSourceType;
use App\Enum\TicketSyncState;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\JiraEpicResolver;
use App\Service\Jira\JiraIssueService;
use App\Service\Jira\JiraSnapshotStore;
use App\Service\Jira\JiraVersionService;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;
use App\Service\ReleaseNote\Dto\SectionPlan;
use App\Service\ReleaseNote\Dto\SyncPlan;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Actualisation non destructive depuis Jira.
 *
 * Garanties :
 * - aucun titre ni texte éditorial n'est modifié (seules les métadonnées des tickets le sont) ;
 * - les tickets existants restent dans leur section, sauf ceux dont l'Epic ou l'étiquette a changé dans Jira
 *   et qui sont encore dans la section du regroupement automatique (reclassement, désactivable) ;
 *   un ticket classé à la main n'est jamais déplacé ;
 * - aucune section n'est réinitialisée ; seule une section automatique vidée par le reclassement et
 *   sans aucun texte rédigé est supprimée ;
 * - les tickets retirés/inaccessibles sont signalés, détachés uniquement sur demande explicite ;
 * - les nouveaux tickets sont ajoutés sans doublon (identifiant Jira puis clé).
 */
class ReleaseRefreshService
{
    public function __construct(
        private readonly JiraVersionService $versionService,
        private readonly JiraIssueService $issueService,
        private readonly JiraEpicResolver $epicResolver,
        private readonly IssueTypeMappingResolver $mappingResolver,
        private readonly SectionBuilder $sectionBuilder,
        private readonly JiraTicketMapper $ticketMapper,
        private readonly EntityManagerInterface $em,
        private readonly JiraSnapshotStore $snapshots,
    ) {
    }

    /**
     * Calcule les changements sans rien modifier.
     *
     * @param bool $remember mémorise les données Jira lues pour que la confirmation ne relise pas Jira
     */
    public function plan(Release $release, bool $remember = false): SyncPlan
    {
        return $this->computePlan($release, $remember, false);
    }

    private function computePlan(Release $release, bool $remember, bool $reuseRemembered): SyncPlan
    {
        $project = $release->getProject();
        $connection = $project?->getJiraConnection();
        if (null === $project || null === $connection) {
            throw ExternalApiException::configuration('Le projet de cette release n\'a plus de connexion Jira.');
        }

        $snapshotKey = sprintf('refresh|%d|%d|%s|%s', $release->getId(), $connection->getId(), $project->getJiraProjectKey(), $release->getJiraVersionId());
        $snapshot = $reuseRemembered ? $this->snapshots->take($snapshotKey) : null;
        if (\is_array($snapshot) && 3 === \count($snapshot)) {
            [$version, $issues, $epics] = $snapshot;
        } else {
            $version = $this->versionService->getVersion($project, $release->getJiraVersionId());
            $issues = $this->issueService->findByFixVersion($project, $version);
            $epics = $this->epicResolver->resolve($connection, $issues);
        }
        if ($remember) {
            $this->snapshots->save($snapshotKey, [$version, $issues, $epics]);
        }
        $rules = $this->mappingResolver->forProject($project);

        $plan = new SyncPlan($version);
        $issuesById = [];
        foreach ($issues as $issue) {
            $issuesById[$issue->id] = $issue;
        }

        $matchedIssueIds = [];
        $missing = [];
        foreach ($release->getTickets() as $ticket) {
            $issue = $issuesById[$ticket->getJiraIssueId()] ?? null;
            if (null === $issue) {
                foreach ($issues as $candidate) {
                    if (0 === strcasecmp($candidate->key, $ticket->getJiraKey())) {
                        $issue = $candidate;
                        break;
                    }
                }
            }
            if (null === $issue) {
                $missing[] = $ticket;
                continue;
            }
            $matchedIssueIds[$issue->id] = true;
            $this->classifyExisting($plan, $release, $rules, $ticket, $issue, $epics[$issue->key] ?? null);
        }

        // Tickets absents de la JQL : retirés de la version, ou inaccessibles.
        if ([] !== $missing) {
            $found = $this->issueService->findByKeys($connection, array_map(static fn (JiraTicket $t) => $t->getJiraKey(), $missing));
            $foundIssues = array_values($found);
            $missingEpics = [] === $foundIssues ? [] : $this->epicResolver->resolve($connection, $foundIssues);
            foreach ($missing as $ticket) {
                $issue = $found[$ticket->getJiraKey()] ?? null;
                if (null === $issue) {
                    $plan->inaccessible[] = $ticket;
                } elseif ($ticket->isAddedManually()) {
                    $this->classifyExisting($plan, $release, $rules, $ticket, $issue, $missingEpics[$issue->key] ?? null);
                } else {
                    $plan->removedFromVersion[] = ['ticket' => $ticket, 'issue' => $issue, 'epic' => $missingEpics[$issue->key] ?? null];
                }
            }
        }

        // Nouveaux tickets.
        foreach ($issues as $issue) {
            if (isset($matchedIssueIds[$issue->id])) {
                continue;
            }
            $epic = $epics[$issue->key] ?? null;
            $sectionPlan = $this->sectionBuilder->planFor($issue, $epic, $rules);
            $section = $this->findSection($release, $sectionPlan);
            if (null === $section) {
                $plan->newSections[$sectionPlan->groupKey()] ??= $sectionPlan;
            }
            $plan->new[] = ['issue' => $issue, 'epic' => $epic, 'section' => $section, 'plan' => $sectionPlan];
        }

        return $plan;
    }

    /**
     * Applique l'actualisation.
     *
     * @param bool $createSections crée les sections proposées pour les nouveaux Epics/types (sinon : tickets « non classés »)
     * @param bool $detachRemoved  détache de leur section les tickets retirés/inaccessibles (action éditoriale explicite)
     * @param bool $reclassify     déplace les tickets dont l'Epic ou l'étiquette a changé (s'ils n'ont pas été classés à la main)
     * @param bool $reuseRemembered applique les données Jira de l'aperçu affiché (si encore disponibles) sans relire Jira
     *
     * @return array<string, int|string>
     */
    public function apply(Release $release, bool $createSections = true, bool $detachRemoved = false, bool $reclassify = true, bool $reuseRemembered = false): array
    {
        try {
            $plan = $this->computePlan($release, false, $reuseRemembered);
        } catch (ExternalApiException $e) {
            $release->recordSyncError($e->getMessage());
            $this->em->flush();
            throw $e;
        }

        $version = $plan->version;
        // Métadonnées de version Jira uniquement : le titre et la date éditoriale restent inchangés.
        $release->setJiraVersionName($version->name)
            ->setJiraVersionDescription($version->description)
            ->setJiraVersionReleased($version->released)
            ->setJiraVersionReleaseDate($version->releaseDate);

        foreach ([...$plan->updated, ...$plan->unchanged] as $row) {
            $this->ticketMapper->apply($row['ticket'], $row['issue'], $row['epic']);
        }
        foreach ($plan->removedFromVersion as $row) {
            $this->ticketMapper->apply($row['ticket'], $row['issue'], $row['epic']);
            $row['ticket']->setSyncState(TicketSyncState::RemovedFromVersion);
            if ($detachRemoved) {
                $row['ticket']->getSection()?->removeTicket($row['ticket']);
            }
        }
        foreach ($plan->inaccessible as $ticket) {
            $ticket->setSyncState(TicketSyncState::Inaccessible);
            $ticket->setLastSyncedAt(new \DateTimeImmutable());
            if ($detachRemoved) {
                $ticket->getSection()?->removeTicket($ticket);
            }
        }

        /** @var array<string, ReleaseSection> $createdSections */
        $createdSections = [];
        if ($createSections) {
            $position = $release->getNextSectionPosition();
            foreach ($plan->newSections as $groupKey => $sectionPlan) {
                $section = (new ReleaseSection())
                    ->setSourceType($sectionPlan->sourceType)
                    ->setSourceKey($sectionPlan->sourceKey)
                    ->setCategory($sectionPlan->category)
                    ->setTitle($sectionPlan->title)
                    ->setPosition($position)
                    ->applyDefaultVisibility();
                $position += 10;
                $release->addSection($section);
                $createdSections[$groupKey] = $section;
            }
        }

        $added = 0;
        foreach ($plan->new as $row) {
            $issue = $row['issue'];
            // Garde anti-doublon (identifiant puis clé).
            if (null !== $release->findTicketByIssueId($issue->id) || null !== $release->findTicketByKey($issue->key)) {
                continue;
            }
            $ticket = new JiraTicket();
            $this->ticketMapper->apply($ticket, $issue, $row['epic']);
            $release->addTicket($ticket);

            $section = $row['section'] ?? $createdSections[$row['plan']->groupKey()] ?? null;
            if (null !== $section) {
                $ticket->setPosition($section->getNextTicketPosition());
                $section->addTicket($ticket);
            } else {
                $ticket->setPosition(0);
            }
            ++$added;
        }

        $reclassified = 0;
        $removedSections = 0;
        if ($reclassify) {
            $sources = [];
            foreach ($plan->regroupings as $row) {
                $target = $row['target'] ?? $createdSections[$row['plan']->groupKey()] ?? null;
                if (!$row['automatic'] || null === $target) {
                    continue;
                }
                $ticket = $row['ticket'];
                if (null !== $row['from']) {
                    $row['from']->removeTicket($ticket);
                    $sources[spl_object_id($row['from'])] = $row['from'];
                }
                $ticket->setPosition($target->getNextTicketPosition());
                $target->addTicket($ticket);
                ++$reclassified;
            }
            // Section automatique vidée, sans texte rédigé : elle n'a plus de raison d'être.
            foreach ($sources as $section) {
                if ($section->getTickets()->isEmpty() && SectionSourceType::Manual !== $section->getSourceType() && !$section->hasEditorialContent()) {
                    $release->removeSection($section);
                    ++$removedSections;
                }
            }
        }

        $summary = $plan->summary();
        $summary['added'] = $added;
        $summary['regroupings'] = $reclassified;
        $summary['sections_removed'] = $removedSections;
        $summary['sections_created'] = \count($createdSections);
        $summary['detached'] = $detachRemoved ? \count($plan->removedFromVersion) + \count($plan->inaccessible) : 0;
        $summary['type'] = 'refresh';
        $release->recordSync($summary);
        $release->touch();
        $this->em->flush();

        return $summary;
    }

    private function classifyExisting(SyncPlan $plan, Release $release, IssueTypeRuleSet $rules, JiraTicket $ticket, JiraIssue $issue, ?EpicReference $epic): void
    {
        $this->detectRegrouping($plan, $release, $rules, $ticket, $issue, $epic);

        // Simulation sur une copie : le ticket réel n'est pas modifié pendant le calcul du plan.
        $copy = clone $ticket;
        $changes = $this->ticketMapper->apply($copy, $issue, $epic);
        if ($ticket->getEpicKey() !== $epic?->key) {
            $plan->epicChanges[] = ['ticket' => $ticket, 'from' => $ticket->getEpicKey(), 'to' => $epic?->key];
        }
        $row = ['ticket' => $ticket, 'issue' => $issue, 'epic' => $epic];
        if ([] === $changes) {
            $plan->unchanged[] = $row;
        } else {
            $plan->updated[] = $row + ['changes' => $changes];
        }
    }

    /**
     * Compare le regroupement d'avant (données du ticket) à celui d'après (données Jira).
     * Le ticket n'est déplaçable que s'il se trouve encore dans la section du regroupement automatique.
     */
    private function detectRegrouping(SyncPlan $plan, Release $release, IssueTypeRuleSet $rules, JiraTicket $ticket, JiraIssue $issue, ?EpicReference $epic): void
    {
        $from = $ticket->getSection();
        if (null === $from || $ticket->isExcluded()) {
            return;
        }
        $target = $this->sectionBuilder->planFor($issue, $epic, $rules);
        if ($target->matches($from)) {
            return;
        }
        $before = $this->sectionBuilder->planForTicket($ticket, $rules);
        if ($before->groupKey() === $target->groupKey()) {
            // Regroupement inchangé dans Jira : le ticket est là où l'utilisateur l'a mis.
            return;
        }
        $automatic = $before->matches($from)
            // Section d'étiquette créée avant une modification de la table de conversion (ex. étiquette ignorée depuis).
            || (SectionSourceType::Label === $from->getSourceType()
                && \in_array(mb_strtolower((string) $from->getSourceKey()), array_map(mb_strtolower(...), $ticket->getLabels()), true));

        $section = $this->findSection($release, $target);
        if ($automatic && null === $section) {
            $plan->newSections[$target->groupKey()] ??= $target;
        }
        $plan->regroupings[] = ['ticket' => $ticket, 'from' => $from, 'plan' => $target, 'target' => $section, 'automatic' => $automatic];
    }

    private function findSection(Release $release, SectionPlan $plan): ?ReleaseSection
    {
        foreach ($release->getSections() as $section) {
            if ($plan->matches($section)) {
                return $section;
            }
        }
        // Une section manuelle ou renommée n'est jamais devinée : pas de rapprochement par titre.
        return null;
    }
}
