<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationFormat: string
{
    case It = 'it';
    case Functional = 'functional';
    case Communication = 'communication';
    /** Procès-verbal de recette de la version (publié avec les release notes). */
    case AcceptanceReport = 'acceptance';

    /** @return list<self> les trois formats de release note (hors PV de recette) */
    public static function releaseNoteFormats(): array
    {
        return [self::It, self::Functional, self::Communication];
    }

    public function isReleaseNote(): bool
    {
        return self::AcceptanceReport !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::It => 'IT interne',
            self::Functional => 'Fonctionnel',
            self::Communication => 'Communication',
            self::AcceptanceReport => 'PV de recette',
        };
    }

    public function audience(): string
    {
        return match ($this) {
            self::It => 'Développeurs, exploitation, support et équipes IT',
            self::Functional => 'Product Owners, métiers, support fonctionnel',
            self::Communication => 'Utilisateurs opérationnels',
            self::AcceptanceReport => 'Recette, Product Owner, responsables de livraison',
        };
    }

    /** Suffixe de titre de page Confluence (unicité du titre par espace). */
    public function pageSuffix(): string
    {
        return match ($this) {
            self::It => 'Release note IT',
            self::Functional => 'Release note fonctionnelle',
            self::Communication => 'Nouveautés',
            self::AcceptanceReport => 'PV de recette',
        };
    }
}
