<?php

declare(strict_types=1);

namespace App\Enum;

enum AcceptanceDocumentStatus: string
{
    case Draft = 'draft';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Final => 'Définitif',
        };
    }

    /** Couleur du macro « status » Confluence. */
    public function confluenceColour(): string
    {
        return self::Final === $this ? 'Green' : 'Yellow';
    }
}
