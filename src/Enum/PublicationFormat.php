<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationFormat: string
{
    case It = 'it';
    case Functional = 'functional';
    case Communication = 'communication';

    public function label(): string
    {
        return match ($this) {
            self::It => 'IT interne',
            self::Functional => 'Fonctionnel',
            self::Communication => 'Communication',
        };
    }

    public function audience(): string
    {
        return match ($this) {
            self::It => 'Développeurs, exploitation, support et équipes IT',
            self::Functional => 'Product Owners, métiers, support fonctionnel',
            self::Communication => 'Utilisateurs opérationnels',
        };
    }

    /** Suffixe de titre de page Confluence (unicité du titre par espace). */
    public function pageSuffix(): string
    {
        return match ($this) {
            self::It => 'Release note IT',
            self::Functional => 'Release note fonctionnelle',
            self::Communication => 'Nouveautés',
        };
    }
}
