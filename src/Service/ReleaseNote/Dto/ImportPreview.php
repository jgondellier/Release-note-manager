<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Dto;

use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\Dto\JiraVersion;

/**
 * Résultat d'une lecture Jira préalable à l'import (aucune écriture en base).
 */
final class ImportPreview
{
    /**
     * @param list<JiraIssue>                   $issues
     * @param array<string, EpicReference|null> $epics
     * @param list<SectionPlan>                 $plans
     */
    public function __construct(
        public readonly JiraVersion $version,
        public readonly array $issues,
        public readonly array $epics,
        public readonly array $plans,
    ) {
    }

    /** @return array<string, JiraIssue> */
    public function issuesByKey(): array
    {
        $map = [];
        foreach ($this->issues as $issue) {
            $map[$issue->key] = $issue;
        }

        return $map;
    }

    public function issueCount(): int
    {
        return \count($this->issues);
    }
}
