<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Fabrique de réponses Jira réalistes (format API REST).
 */
final class JiraFixtures
{
    /**
     * @param array<string, mixed> $overrides champs « fields » à surcharger
     *
     * @return array<string, mixed>
     */
    public static function issue(string $key, string $type, string $summary, array $overrides = [], string $versionId = '10042', bool $epicType = false): array
    {
        $id = (string) (90000 + (int) substr($key, strrpos($key, '-') + 1));

        return [
            'id' => $id,
            'key' => $key,
            'fields' => array_replace([
                'summary' => $summary,
                'issuetype' => ['name' => $type, 'subtask' => false, 'hierarchyLevel' => $epicType ? 1 : 0],
                'status' => ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']],
                'priority' => ['name' => 'Medium'],
                'components' => [['name' => 'Back-office']],
                'labels' => [],
                'fixVersions' => [['id' => $versionId, 'name' => '2.4.0']],
                'description' => null,
                'updated' => '2026-10-01T10:00:00.000+0200',
            ], $overrides),
        ];
    }

    /** @return array<string, mixed> */
    public static function parent(string $key, string $summary, bool $isEpic = true): array
    {
        return ['key' => $key, 'fields' => ['summary' => $summary, 'issuetype' => ['name' => $isEpic ? 'Epic' : 'Story', 'hierarchyLevel' => $isEpic ? 1 : 0]]];
    }

    /** @return list<array<string, mixed>> */
    public static function versions(): array
    {
        return [
            ['id' => '10042', 'name' => '2.4.0', 'released' => false, 'archived' => false, 'releaseDate' => '2026-10-20', 'description' => 'Version d\'automne'],
            ['id' => '10043', 'name' => '2.5.0', 'released' => false, 'archived' => false],
            ['id' => '10001', 'name' => '2.3.0', 'released' => true, 'archived' => false, 'releaseDate' => '2026-06-01'],
        ];
    }
}
