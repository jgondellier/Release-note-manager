<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationTarget: string
{
    case Cloud = 'cloud';
    case Server = 'server';

    public function label(): string
    {
        return match ($this) {
            self::Cloud => 'Confluence Cloud',
            self::Server => 'Confluence Server / Data Center',
        };
    }

    public function deploymentType(): DeploymentType
    {
        return match ($this) {
            self::Cloud => DeploymentType::Cloud,
            self::Server => DeploymentType::Server,
        };
    }
}
