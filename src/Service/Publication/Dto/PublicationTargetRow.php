<?php

declare(strict_types=1);

namespace App\Service\Publication\Dto;

use App\Entity\Publication;
use App\Entity\PublicationContainer;
use App\Enum\PublicationFormat;
use App\Enum\PublicationTarget;

/**
 * Ligne de la matrice de publication (format × instance Confluence).
 */
final class PublicationTargetRow
{
    public function __construct(
        public readonly PublicationFormat $format,
        public readonly PublicationTarget $target,
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?string $connectionName,
        public readonly ?string $spaceKey,
        public readonly ?string $parentPageId,
        public readonly string $pageTitle,
        public readonly string $containerTitle,
        public readonly ?PublicationContainer $container,
        public readonly ?Publication $publication,
        public readonly bool $outdated,
    ) {
    }

    public function key(): string
    {
        return self::buildKey($this->format, $this->target);
    }

    public static function buildKey(PublicationFormat $format, PublicationTarget $target): string
    {
        return $format->value . ':' . $target->value;
    }

    /** Emplacement de la page : sous la page de regroupement de la release. */
    public function locationLabel(): string
    {
        $location = null !== $this->container
            ? sprintf('sous « %s » (page n°%s)', $this->containerTitle, $this->container->getConfluencePageId())
            : sprintf('sous « %s » (créée à la première publication)', $this->containerTitle);

        return $location . (null !== $this->parentPageId ? sprintf(', elle-même sous la page %s', $this->parentPageId) : ', à la racine de l\'espace');
    }

    /** Résultat attendu, affiché sur l'écran de confirmation. */
    public function expectedOutcome(): string
    {
        if (null !== $this->publication?->getConfluencePageId()) {
            return sprintf('Mise à jour de la page existante (n°%s, version %d → %d)', $this->publication->getConfluencePageId(), (int) $this->publication->getConfluencePageVersion(), (int) $this->publication->getConfluencePageVersion() + 1);
        }

        return 'Création de la page (ou mise à jour si une page de même titre existe déjà dans l\'espace)';
    }
}
