<?php

declare(strict_types=1);

namespace App\Enum;

enum SectionSourceType: string
{
    case Epic = 'epic';
    case IssueType = 'issue_type';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Epic => 'Epic Jira',
            self::IssueType => 'Type de ticket',
            self::Manual => 'Section manuelle',
        };
    }
}
