<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Entity\ReleaseSection;
use App\Enum\SectionCategory;
use App\Enum\SectionSourceType;

/**
 * Section proposée par le regroupement automatique (avant persistance).
 */
final class SectionPlan
{
    /** @var list<string> clés Jira, dans l'ordre */
    public array $issueKeys = [];

    public function __construct(
        public readonly SectionSourceType $sourceType,
        public readonly string $sourceKey,
        public readonly string $title,
        public SectionCategory $category,
        public readonly int $sortWeight,
    ) {
    }

    /** Clé de regroupement (type de source + clé, casse ignorée). */
    public function groupKey(): string
    {
        return $this->sourceType->value . ':' . mb_strtolower($this->sourceKey);
    }

    public function matches(?ReleaseSection $section): bool
    {
        return null !== $section
            && $section->getSourceType() === $this->sourceType
            && null !== $section->getSourceKey()
            && 0 === strcasecmp($section->getSourceKey(), $this->sourceKey);
    }
}
