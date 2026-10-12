<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\Project;
use App\Entity\Release;
use App\Enum\PublicationFormat;

/**
 * Modèle de vue d'une release note pour un format : partagé par l'aperçu web et le rendu Confluence.
 */
final class ReleaseNoteView
{
    /**
     * @param list<SectionView>                       $sections
     * @param list<SectionView>                       $pendingSections sections dont la livraison n'est pas confirmée (communication)
     * @param list<array{label: string, url: string}> $links
     * @param list<array{title: string, text: string}> $actions
     * @param array<string, int>                      $typeStats
     * @param list<string>                            $components
     * @param list<string>                            $warnings    avertissements éditoriaux (affichés dans l'éditeur uniquement)
     */
    public function __construct(
        public readonly PublicationFormat $format,
        public readonly Release $release,
        public readonly Project $project,
        public readonly string $title,
        public readonly ?string $intro,
        public readonly array $sections,
        public readonly array $pendingSections,
        public readonly array $links,
        public readonly array $actions,
        public readonly array $typeStats,
        public readonly array $components,
        public readonly int $ticketCount,
        public readonly ?string $jiraVersionUrl,
        public readonly array $warnings,
    ) {
    }

    /** @return array<string, list<SectionView>> sections regroupées par catégorie (ordre d'affichage conservé) */
    public function sectionsByCategory(): array
    {
        $groups = [];
        foreach ($this->sections as $view) {
            $groups[$view->category->value][] = $view;
        }

        return $groups;
    }
}
