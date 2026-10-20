<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\Project;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraIssue;

/**
 * Couverture de tests Octane (OpenText Core Software Delivery Platform) lue via l'API REST
 * de l'extension Jira « octane-jira-coverage-plugin » (encart « test coverage » des tickets),
 * avec la connexion Jira du projet :
 *
 * - GET /rest/octane-coverage/1.0/coverage/octane-workspaces?project-key=…&issue-type=…
 *   → configurations d'espaces Octane applicables (découverte de workspace-config-id / workspace-id) ;
 * - GET /rest/octane-coverage/1.0/coverage?project-key=…&issue-key=…&issue-id=…&workspace-config-id=…&workspace-id=…
 *   → entité Octane liée, nombre de tests, résultats des derniers passages par statut.
 */
class OctaneCoverageService
{
    private const BASE = '/rest/octane-coverage/1.0/coverage';

    /** @var array<string, array{0: string, 1: string}|null> espace découvert par type de ticket */
    private array $workspaceCache = [];

    public function __construct(private readonly AtlassianApiClient $client)
    {
    }

    /**
     * @return array<string, mixed> couverture normalisée :
     *   {status, entity: ?{id, name, url, testTabUrl}, totalTests, executedTests, groups: list<{id, name, count, percentage}>}
     *
     * @throws ExternalApiException
     */
    public function getCoverage(Project $project, JiraIssue $issue): array
    {
        $connection = $project->getJiraConnection();
        if (null === $connection) {
            throw ExternalApiException::configuration('Le projet n\'a pas de connexion Jira.');
        }
        $workspace = $this->workspace($project, $issue->issueType);
        if (null === $workspace) {
            return ['status' => 'noValidConfiguration', 'entity' => null, 'totalTests' => 0, 'executedTests' => 0, 'groups' => []];
        }

        $data = $this->client->get($connection, self::BASE, [
            'project-key' => $project->getJiraProjectKey(),
            'issue-key' => $issue->key,
            'issue-id' => $issue->id,
            'workspace-config-id' => $workspace[0],
            'workspace-id' => $workspace[1],
        ]);

        return self::normalize($data);
    }

    /**
     * Normalise la réponse de l'extension (objets sérialisés sous « fields » ou à plat selon les versions).
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $status = \is_string($data['status'] ?? null) ? $data['status'] : 'unknown';
        $entity = null;
        if (\is_array($data['octaneEntity'] ?? null)) {
            $e = self::fields($data['octaneEntity']);
            $entity = [
                'id' => isset($e['id']) ? (string) $e['id'] : null,
                'name' => isset($e['name']) ? (string) $e['name'] : null,
                'url' => self::safeUrl($e['url'] ?? null),
                'testTabUrl' => self::safeUrl($e['testTabUrl'] ?? null),
            ];
        }
        $groups = [];
        foreach ((array) ($data['coverageGroups'] ?? []) as $group) {
            if (!\is_array($group)) {
                continue;
            }
            $g = self::fields($group);
            $count = (int) ($g['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $groups[] = [
                'id' => (string) ($g['id'] ?? ''),
                'name' => (string) ($g['name'] ?? $g['id'] ?? '?'),
                'count' => $count,
                'percentage' => isset($g['percentage']) ? (string) $g['percentage'] : null,
                'order' => (int) ($g['order'] ?? 99),
            ];
        }
        usort($groups, static fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return [
            'status' => $status,
            'entity' => $entity,
            'totalTests' => (int) ($data['totalTestsCount'] ?? 0),
            'executedTests' => (int) ($data['totalExecutedTestsCount'] ?? array_sum(array_column($groups, 'count'))),
            'groups' => array_map(static fn (array $g) => array_diff_key($g, ['order' => true]), $groups),
        ];
    }

    /**
     * Couverture cumulée d'une liste de fiches.
     *
     * @param list<array<string, mixed>> $coverages
     *
     * @return array{withData: int, totalTests: int, executedTests: int, groups: list<array{id: string, name: string, count: int}>}
     */
    public static function aggregate(array $coverages): array
    {
        $groups = [];
        $total = 0;
        $executed = 0;
        $withData = 0;
        foreach ($coverages as $coverage) {
            if ('hasData' !== ($coverage['status'] ?? null)) {
                continue;
            }
            ++$withData;
            $total += (int) $coverage['totalTests'];
            $executed += (int) $coverage['executedTests'];
            foreach ($coverage['groups'] as $g) {
                $groups[$g['id']] ??= ['id' => $g['id'], 'name' => $g['name'], 'count' => 0];
                $groups[$g['id']]['count'] += $g['count'];
            }
        }

        return ['withData' => $withData, 'totalTests' => $total, 'executedTests' => $executed, 'groups' => array_values($groups)];
    }

    /** @return array{0: string, 1: string}|null [workspace-config-id, workspace-id] */
    private function workspace(Project $project, string $issueType): ?array
    {
        if (null !== $project->getOctaneWorkspaceConfigId() && null !== $project->getOctaneWorkspaceId()) {
            return [$project->getOctaneWorkspaceConfigId(), $project->getOctaneWorkspaceId()];
        }
        $cacheKey = $project->getJiraProjectKey() . '|' . $issueType;
        if (\array_key_exists($cacheKey, $this->workspaceCache)) {
            return $this->workspaceCache[$cacheKey];
        }
        $connection = $project->getJiraConnection();
        \assert(null !== $connection);
        $configs = $this->client->get($connection, self::BASE . '/octane-workspaces', [
            'project-key' => $project->getJiraProjectKey(),
            'issue-type' => $issueType,
        ]);
        $found = null;
        foreach ($configs as $config) {
            if (!\is_array($config) || !isset($config['id'])) {
                continue;
            }
            foreach ((array) ($config['octaneWorkspaces'] ?? []) as $ws) {
                if (\is_array($ws) && isset($ws['id'])) {
                    $found = [(string) $config['id'], (string) $ws['id']];
                    break 2;
                }
            }
        }

        return $this->workspaceCache[$cacheKey] = $found;
    }

    /** @param array<mixed> $object */
    private static function fields(array $object): array
    {
        return \is_array($object['fields'] ?? null) ? $object['fields'] + $object : $object;
    }

    private static function safeUrl(mixed $url): ?string
    {
        return \is_string($url) && 1 === preg_match('#^https?://#i', $url) ? $url : null;
    }
}
