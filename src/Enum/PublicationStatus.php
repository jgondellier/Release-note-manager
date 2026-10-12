<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Jamais publiée',
            self::InProgress => 'En cours',
            self::Success => 'Publiée',
            self::Failed => 'En échec',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::InProgress => 'info',
            self::Success => 'success',
            self::Failed => 'danger',
        };
    }
}
