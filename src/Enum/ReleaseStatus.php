<?php

declare(strict_types=1);

namespace App\Enum;

enum ReleaseStatus: string
{
    case Draft = 'draft';
    case InPreparation = 'in_preparation';
    case Ready = 'ready';
    case PartiallyPublished = 'partially_published';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::InPreparation => 'En préparation',
            self::Ready => 'Prête à publier',
            self::PartiallyPublished => 'Partiellement publiée',
            self::Published => 'Publiée',
            self::Archived => 'Archivée',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::InPreparation => 'info',
            self::Ready => 'primary',
            self::PartiallyPublished => 'warning',
            self::Published => 'success',
            self::Archived => 'muted',
        };
    }

    /**
     * Statuts que l'utilisateur peut choisir manuellement (les statuts de
     * publication sont calculés par le PublicationService).
     *
     * @return list<self>
     */
    public static function editorialChoices(): array
    {
        return [self::Draft, self::InPreparation, self::Ready];
    }

    public function isEditable(): bool
    {
        return self::Archived !== $this;
    }
}
