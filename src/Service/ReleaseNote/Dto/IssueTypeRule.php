<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Enum\SectionCategory;

final class IssueTypeRule
{
    public function __construct(
        public readonly string $sectionTitle,
        public readonly SectionCategory $category,
        public readonly int $position,
    ) {
    }
}
