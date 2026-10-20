<?php

declare(strict_types=1);

namespace App\Service\Jira\Dto;

/**
 * Ticket Jira normalisé, indépendant des différences Cloud (API v3, ADF) et Server (API v2).
 */
final class JiraIssue
{
    /**
     * @param list<string>                         $components
     * @param list<string>                         $labels
     * @param list<array{id: string, name: string}> $fixVersions
     * @param array<string, mixed>                 $diagnostic sous-ensemble non sensible de la réponse brute
     * @param list<array{key: string, summary: string, type: string, status: ?string, statusCategory: ?string, priority: ?string, link: string}> $linkedIssues
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $issueType,
        public readonly bool $isEpic,
        public readonly bool $isSubtask,
        public readonly string $summary,
        public readonly ?string $description,
        public readonly ?string $status,
        public readonly ?string $statusCategory,
        public readonly ?string $priority,
        public readonly array $components,
        public readonly array $labels,
        public readonly array $fixVersions,
        public readonly ?string $parentKey,
        public readonly bool $parentIsEpic,
        public readonly ?string $parentSummary,
        public readonly ?string $epicLinkKey,
        public readonly string $url,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly array $diagnostic = [],
        public readonly array $linkedIssues = [],
    ) {
    }

    public function hasFixVersion(string $versionId): bool
    {
        foreach ($this->fixVersions as $version) {
            if ($version['id'] === $versionId) {
                return true;
            }
        }

        return false;
    }
}
