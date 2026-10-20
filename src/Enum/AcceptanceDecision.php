<?php

declare(strict_types=1);

namespace App\Enum;

enum AcceptanceDecision: string
{
    case Accepted = 'accepted';
    case AcceptedWithReserves = 'accepted_with_reserves';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Acceptée sans réserve',
            self::AcceptedWithReserves => 'Acceptée avec réserves',
            self::Refused => 'Refusée',
        };
    }

    public function confluenceColour(): string
    {
        return match ($this) {
            self::Accepted => 'Green',
            self::AcceptedWithReserves => 'Yellow',
            self::Refused => 'Red',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::AcceptedWithReserves => 'warning',
            self::Refused => 'danger',
        };
    }
}
