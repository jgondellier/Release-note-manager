<?php

declare(strict_types=1);

namespace App\Enum;

enum TicketSyncState: string
{
    case Active = 'active';
    /** Le ticket existe toujours mais ne porte plus la Fix Version de la release. */
    case RemovedFromVersion = 'removed_from_version';
    /** Le ticket n'est plus retourné par Jira (supprimé ou droits insuffisants). */
    case Inaccessible = 'inaccessible';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::RemovedFromVersion => 'Retiré de la version',
            self::Inaccessible => 'Inaccessible',
        };
    }
}
