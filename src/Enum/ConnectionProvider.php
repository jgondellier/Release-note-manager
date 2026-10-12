<?php

declare(strict_types=1);

namespace App\Enum;

enum ConnectionProvider: string
{
    case Jira = 'jira';
    case Confluence = 'confluence';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Confluence => 'Confluence',
        };
    }
}
