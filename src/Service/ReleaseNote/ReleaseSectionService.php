<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\SectionSourceType;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\JiraEpicResolver;
use App\Service\Jira\JiraIssueService;
use App\Service\Jira\JiraJqlBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opérations éditoriales sur les sections et le classement des tickets.
 */
class ReleaseSectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JiraIssueService $issueService,
        private readonly JiraEpicResolver $epicResolver,
        private readonly IssueTypeMappingResolver $mappingResolver,
        private readonly SectionBuilder $sectionBuilder,
        private readonly JiraTicketMapper $ticketMapper,
    ) {
    }

    public function createManualSection(Release $release, ReleaseSection $section): ReleaseSection
    {
        $this->assertEditable($release);
        $section->setSourceType(SectionSourceType::Manual)
            ->setSourceKey(null)
            ->setPosition($release->getNextSectionPosition());
        $release->addSection($section);
        $release->touch();
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    public function save(ReleaseSection $section): void
    {
        $release = $section->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $release->touch();
        $this->em->flush();
    }

    /** Supprime une section ; ses tickets deviennent « non classés » (ils ne sont pas supprimés). */
    public function deleteSection(ReleaseSection $section): int
    {
        $release = $section->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $count = 0;
        foreach ($section->getTickets()->toArray() as $ticket) {
            $section->removeTicket($ticket);
            $ticket->setPosition(0);
            ++$count;
        }
        $release->removeSection($section);
        $release->touch();
        $this->em->remove($section);
        $this->em->flush();

        return $count;
    }

    public function moveSection(ReleaseSection $section, string $direction): void
    {
        $release = $section->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $sections = $this->sortedSections($release);
        $index = array_search($section, $sections, true);
        $target = 'up' === $direction ? $index - 1 : $index + 1;
        if (false === $index || !isset($sections[$target])) {
            return;
        }
        [$sections[$index], $sections[$target]] = [$sections[$target], $sections[$index]];
        $this->renumberSections($sections);
        $release->touch();
        $this->em->flush();
    }

    /**
     * Réordonne toutes les sections selon une liste d'identifiants (glisser-déposer).
     *
     * @param list<int> $orderedIds
     */
    public function reorderSections(Release $release, array $orderedIds): void
    {
        $this->assertEditable($release);
        $byId = [];
        foreach ($release->getSections() as $section) {
            $byId[$section->getId()] = $section;
        }
        $ordered = [];
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        // Sections non citées : conservées à la fin dans leur ordre actuel.
        $rest = array_values($byId);
        usort($rest, static fn (ReleaseSection $a, ReleaseSection $b) => $a->getPosition() <=> $b->getPosition());
        $this->renumberSections([...$ordered, ...$rest]);
        $release->touch();
        $this->em->flush();
    }

    /** Déplace un ticket vers une autre section (ou « non classé » si null), en fin de section. */
    public function moveTicket(JiraTicket $ticket, ?ReleaseSection $target): void
    {
        $release = $ticket->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        if (null !== $target && $target->getRelease() !== $release) {
            throw new \InvalidArgumentException('La section cible n\'appartient pas à cette release.');
        }
        $ticket->getSection()?->removeTicket($ticket);
        if (null !== $target) {
            $ticket->setPosition($target->getNextTicketPosition());
            $target->addTicket($ticket);
        } else {
            $ticket->setPosition(0);
        }
        $release->touch();
        $this->em->flush();
    }

    public function moveTicketWithinSection(JiraTicket $ticket, string $direction): void
    {
        $section = $ticket->getSection();
        if (null === $section) {
            return;
        }
        $release = $section->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $tickets = $section->getTickets()->toArray();
        usort($tickets, static fn (JiraTicket $a, JiraTicket $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
        $tickets = array_values($tickets);
        $index = array_search($ticket, $tickets, true);
        $target = 'up' === $direction ? $index - 1 : $index + 1;
        if (false === $index || !isset($tickets[$target])) {
            return;
        }
        [$tickets[$index], $tickets[$target]] = [$tickets[$target], $tickets[$index]];
        foreach ($tickets as $i => $t) {
            $t->setPosition(($i + 1) * 10);
        }
        $release->touch();
        $this->em->flush();
    }

    /** Retire un ticket de la release (exclusion conservée lors des actualisations). */
    public function excludeTicket(JiraTicket $ticket): void
    {
        $release = $ticket->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $ticket->getSection()?->removeTicket($ticket);
        $ticket->setExcluded(true)->setPosition(0);
        $release->touch();
        $this->em->flush();
    }

    public function includeTicket(JiraTicket $ticket): void
    {
        $release = $ticket->getRelease();
        \assert(null !== $release);
        $this->assertEditable($release);
        $ticket->setExcluded(false);
        $release->touch();
        $this->em->flush();
    }

    /**
     * Ajoute un ticket par sa clé Jira (même s'il ne porte pas la version : il est alors signalé).
     *
     * @return array{ticket: JiraTicket, warning: ?string}
     */
    public function addTicketByKey(Release $release, string $key, ?ReleaseSection $target = null): array
    {
        $this->assertEditable($release);
        $key = strtoupper(trim($key));
        if (!JiraJqlBuilder::isValidIssueKey($key)) {
            throw new \InvalidArgumentException('Clé Jira invalide (format attendu : ABC-123).');
        }
        if (null !== $existing = $release->findTicketByKey($key)) {
            if ($existing->isExcluded()) {
                $existing->setExcluded(false);
                $this->em->flush();

                return ['ticket' => $existing, 'warning' => 'Ce ticket avait été retiré : il est de nouveau inclus (non classé).'];
            }
            throw new \InvalidArgumentException(sprintf('Le ticket %s est déjà présent dans cette release.', $key));
        }
        $project = $release->getProject();
        $connection = $project?->getJiraConnection();
        if (null === $project || null === $connection) {
            throw ExternalApiException::configuration('Le projet n\'a pas de connexion Jira.');
        }

        $issue = $this->issueService->getIssue($connection, $key);
        if (null === $issue) {
            throw new ExternalApiException(sprintf('Ticket %s introuvable ou inaccessible.', $key), ExternalApiException::NOT_FOUND, 404);
        }
        if (null !== $release->findTicketByIssueId($issue->id)) {
            throw new \InvalidArgumentException(sprintf('Le ticket %s est déjà présent (sous une autre clé).', $key));
        }
        $epic = $this->epicResolver->resolve($connection, [$issue])[$issue->key] ?? null;

        $ticket = new JiraTicket();
        $this->ticketMapper->apply($ticket, $issue, $epic);
        $inVersion = $issue->hasFixVersion($release->getJiraVersionId());
        $ticket->setAddedManually(!$inVersion);
        $release->addTicket($ticket);

        if (null === $target) {
            $plan = $this->sectionBuilder->planFor($issue, $epic, $this->mappingResolver->forProject($project));
            foreach ($release->getSections() as $section) {
                if ($section->getSourceType() === $plan->sourceType && null !== $section->getSourceKey() && 0 === strcasecmp($section->getSourceKey(), $plan->sourceKey)) {
                    $target = $section;
                    break;
                }
            }
        }
        if (null !== $target) {
            $ticket->setPosition($target->getNextTicketPosition());
            $target->addTicket($ticket);
        }
        $release->touch();
        $this->em->flush();

        $warning = $inVersion ? null : sprintf('Attention : %s ne porte pas la version « %s » dans Jira.', $key, $release->getJiraVersionName());

        return ['ticket' => $ticket, 'warning' => $warning];
    }

    /** @return list<ReleaseSection> */
    private function sortedSections(Release $release): array
    {
        $sections = $release->getSections()->toArray();
        usort($sections, static fn (ReleaseSection $a, ReleaseSection $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return array_values($sections);
    }

    /** @param list<ReleaseSection> $sections */
    private function renumberSections(array $sections): void
    {
        foreach ($sections as $i => $section) {
            $section->setPosition(($i + 1) * 10);
        }
    }

    private function assertEditable(Release $release): void
    {
        if ($release->isArchived()) {
            throw new \LogicException('Cette release est archivée : désarchivez-la pour la modifier.');
        }
    }
}
