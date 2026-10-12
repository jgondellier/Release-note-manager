<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\JiraTicket;
use App\Entity\ReleaseSection;
use App\Enum\SectionCategory;

/**
 * Section prête à être rendue dans un format donné.
 */
final class SectionView
{
    /**
     * @param list<JiraTicket>                        $tickets
     * @param list<array{label: string, url: string}> $links
     */
    public function __construct(
        public readonly ReleaseSection $section,
        public readonly string $title,
        public readonly SectionCategory $category,
        public readonly ?string $content,
        public readonly bool $delivered,
        public readonly array $tickets,
        public readonly array $links,
    ) {
    }
}
