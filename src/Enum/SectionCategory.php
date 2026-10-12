<?php

declare(strict_types=1);

namespace App\Enum;

enum SectionCategory: string
{
    case Feature = 'feature';
    case Fix = 'fix';
    case Technical = 'technical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Feature => 'Évolution',
            self::Fix => 'Correction',
            self::Technical => 'Technique',
            self::Other => 'Autre',
        };
    }

    /** Couleur du macro « status » Confluence (Grey, Red, Yellow, Green, Blue, Purple). */
    public function confluenceColour(): string
    {
        return match ($this) {
            self::Feature => 'Blue',
            self::Fix => 'Red',
            self::Technical => 'Purple',
            self::Other => 'Grey',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Feature => '✦',
            self::Fix => '✔',
            self::Technical => '⚙',
            self::Other => '•',
        };
    }
}
