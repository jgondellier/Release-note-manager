<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

use App\Entity\JiraTicket;
use App\Entity\Release;
use App\Entity\ReleaseSection;
use App\Enum\PublicationFormat;
use App\Enum\SectionCategory;
use App\Enum\TicketSyncState;
use App\Service\ReleaseNote\Dto\ReleaseNoteView;
use App\Service\ReleaseNote\Dto\SectionView;

/**
 * Construit le modèle de vue d'un format à partir de la release source.
 *
 * Aucun contenu n'est inventé : un champ vide n'est pas affiché. Les seuls
 * replis utilisés sont des textes saisis par l'utilisateur dans un autre champ
 * (ex. descriptif commun si le wording fonctionnel est vide) ; ils sont signalés
 * dans les avertissements de l'éditeur.
 */
final class ReleaseNoteViewBuilder
{
    public function __construct(private readonly LinkListParser $linkParser)
    {
    }

    public function build(Release $release, PublicationFormat $format): ReleaseNoteView
    {
        $project = $release->getProject();
        \assert(null !== $project);

        $sections = $release->getSections()->toArray();
        usort($sections, static fn (ReleaseSection $a, ReleaseSection $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        $views = [];
        $pending = [];
        $actions = [];
        $warnings = [];
        $typeStats = [];
        $components = [];
        $ticketCount = 0;

        foreach ($sections as $section) {
            if (!$section->isVisibleIn($format)) {
                continue;
            }
            $tickets = $section->getActiveTickets();
            usort($tickets, static fn (JiraTicket $a, JiraTicket $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

            [$content, $usedFallback] = $this->contentFor($section, $format);
            $title = $section->getTitleFor($format);
            if ($usedFallback) {
                $warnings[] = sprintf('« %s » : pas de texte spécifique au format %s, le descriptif commun est utilisé.', $title, $format->label());
            }
            if (null === $content && PublicationFormat::It !== $format) {
                $warnings[] = sprintf('« %s » : aucun texte rédigé pour le format %s.', $title, $format->label());
            }
            foreach ($tickets as $ticket) {
                if (TicketSyncState::Active !== $ticket->getSyncState()) {
                    $warnings[] = sprintf('%s (%s) : %s.', $ticket->getJiraKey(), $title, mb_strtolower($ticket->getSyncState()->label()));
                }
            }

            $view = new SectionView(
                section: $section,
                title: $title,
                category: $section->getCategory(),
                content: $content,
                delivered: $section->isDelivered(),
                tickets: $tickets,
                links: $this->linkParser->parse($section->getLinks()),
            );

            if (PublicationFormat::Communication === $format && !$view->delivered) {
                $pending[] = $view;
                $warnings[] = sprintf('« %s » : livraison non confirmée (tickets non terminés) — présentée « en cours de finalisation ».', $title);
            } else {
                $views[] = $view;
            }

            if (null !== $section->getActionRequired()) {
                $actions[] = ['title' => $title, 'text' => $section->getActionRequired()];
            }
            foreach ($tickets as $ticket) {
                ++$ticketCount;
                $typeStats[$ticket->getIssueType()] = ($typeStats[$ticket->getIssueType()] ?? 0) + 1;
                foreach ($ticket->getComponents() as $component) {
                    $components[$component] = true;
                }
            }
        }

        $unassigned = \count($release->getUnassignedTickets());
        if ($unassigned > 0) {
            $warnings[] = sprintf('%d ticket(s) non classé(s) : ils n\'apparaissent dans aucun format.', $unassigned);
        }
        if ([] === $views && [] === $pending) {
            $warnings[] = 'Aucune section visible dans ce format.';
        }
        arsort($typeStats);
        $componentList = array_keys($components);
        sort($componentList, SORT_NATURAL | SORT_FLAG_CASE);

        return new ReleaseNoteView(
            format: $format,
            release: $release,
            project: $project,
            title: $this->titleFor($release, $format),
            intro: $this->introFor($release, $format),
            sections: $views,
            pendingSections: $pending,
            links: $this->linkParser->parse($release->getUsefulLinks()),
            actions: $actions,
            typeStats: $typeStats,
            components: $componentList,
            ticketCount: $ticketCount,
            jiraVersionUrl: $this->jiraVersionUrl($release),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /** @return array{0: ?string, 1: bool} [contenu, repli utilisé] */
    private function contentFor(ReleaseSection $section, PublicationFormat $format): array
    {
        $specific = match ($format) {
            PublicationFormat::It => $section->getItContent(),
            PublicationFormat::Functional => $section->getFunctionalContent(),
            PublicationFormat::Communication => $section->getCommunicationContent() ?? $section->getFunctionalContent(),
            PublicationFormat::AcceptanceReport => null,
        };
        if (PublicationFormat::It === $format) {
            // En IT, le descriptif et le contenu technique sont affichés séparément.
            return [$specific, false];
        }
        if (null !== $specific) {
            return [$specific, false];
        }

        return [$section->getDescription(), null !== $section->getDescription()];
    }

    private function titleFor(Release $release, PublicationFormat $format): string
    {
        return PublicationFormat::Communication === $format && null !== $release->getCommunicationTitle()
            ? $release->getCommunicationTitle()
            : $release->getTitle();
    }

    private function introFor(Release $release, PublicationFormat $format): ?string
    {
        return match ($format) {
            PublicationFormat::It => $release->getItIntro(),
            PublicationFormat::Functional => $release->getFunctionalIntro(),
            PublicationFormat::Communication => $release->getCommunicationIntro(),
            PublicationFormat::AcceptanceReport => null,
        };
    }

    private function jiraVersionUrl(Release $release): ?string
    {
        $project = $release->getProject();
        $connection = $project?->getJiraConnection();
        if (null === $connection || '' === $release->getJiraVersionId()) {
            return null;
        }

        return sprintf('%s/projects/%s/versions/%s', $connection->getBaseUrl(), rawurlencode($project->getJiraProjectKey()), rawurlencode($release->getJiraVersionId()));
    }

    /** Ordre d'affichage des catégories. */
    public static function categoryOrder(): array
    {
        return [SectionCategory::Feature, SectionCategory::Fix, SectionCategory::Technical, SectionCategory::Other];
    }
}
