<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'instance Atlassian : les API Cloud et Server/Data Center diffèrent
 * (versions d'API, pagination, authentification, URL de base Confluence).
 */
enum DeploymentType: string
{
    case Cloud = 'cloud';
    case Server = 'server';

    public function label(): string
    {
        return match ($this) {
            self::Cloud => 'Cloud',
            self::Server => 'Server / Data Center',
        };
    }
}
