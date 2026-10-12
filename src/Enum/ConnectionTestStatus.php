<?php

declare(strict_types=1);

namespace App\Enum;

enum ConnectionTestStatus: string
{
    case Success = 'success';
    case Failure = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Réussi',
            self::Failure => 'Échec',
        };
    }
}
