<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
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
        $workspace = $this->workspace($project, $issue->issueType);
        if (null === $workspace) {
            return self::noConfiguration();
        }

        return self::normalize($this->client->get($this->connection($project), self::BASE, $this->query($project, $issue, $workspace)));
    }

    /**
     * Couverture de plusieurs fiches. La première est interrogée seule : si l'extension est absente ou
     * l'accès refusé, on s'arrête là ; sinon les suivantes partent en parallèle.
     *
     * @param array<array-key, JiraIssue> $issues
     *
     * @return array<array-key, array<string, mixed>|ExternalApiException|null> couverture, erreur, ou null si non interrogée
     */
    public function getCoverages(Project $project, array $issues, int $concurrency = 6): array
    {
        $connection = $this->connection($project);
        $results = array_fill_keys(array_keys($issues), null);
        $requests = [];
        foreach ($issues as $i => $issue) {
            try {
                $workspace = $this->workspace($project, $issue->issueType);
            } catch (ExternalApiException $e) {
                $results[$i] = $e;
                if (self::isBlocking($e)) {
                    return $results;
                }
                continue;
            }
            if (null === $workspace) {
                $results[$i] = self::noConfiguration();
                continue;
            }
            $requests[$i] = [self::BASE, $this->query($project, $issue, $workspace)];
        }
        if ([] === $requests) {
            return $results;
        }

        $firstKey = array_key_first($requests);
        $first = $this->client->getMany($connection, [$firstKey => $requests[$firstKey]])[$firstKey];
        unset($requests[$firstKey]);
        $results[$firstKey] = $first instanceof ExternalApiException ? $first : self::normalize($first);
        if ($first instanceof ExternalApiException && self::isBlocking($first)) {
            return $results;
        }
        foreach ($this->client->getMany($connection, $requests, $concurrency) as $i => $data) {
            $results[$i] = $data instanceof ExternalApiException ? $data : self::normalize($data);
        }

        return $results;
    }

    /** Extension absente ou accès refusé : inutile d'interroger les fiches suivantes. */
    public static function isBlocking(ExternalApiException $e): bool
    {
        return $e->isNotFound() || ExternalApiException::AUTHENTICATION === $e->getCategory();
    }

    /** @return array<string, mixed> */
    private static function noConfiguration(): array
    {
        return ['status' => 'noValidConfiguration', 'entity' => null, 'totalTests' => 0, 'executedTests' => 0, 'groups' => []];
    }

    /**
     * @param array{0: string, 1: string} $workspace
     *
     * @return array<string, string>
     */
    private function query(Project $project, JiraIssue $issue, array $workspace): array
    {
        return [
            'project-key' => $project->getJiraProjectKey(),
            'issue-key' => $issue->key,
            'issue-id' => $issue->id,
            'workspace-config-id' => $workspace[0],
            'workspace-id' => $workspace[1],
        ];
    }

    private function connection(Project $project): ExternalConnection
    {
        $connection = $project->getJiraConnection();
        if (null === $connection) {
            throw ExternalApiException::configuration('Le projet n\'a pas de connexion Jira.');
        }

        return $connection;
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
                'id' => self::canonicalStatus((string) ($g['id'] ?? $g['name'] ?? '')),
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
            $coverage = self::canonicalize($coverage);
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

    /**
     * Identifiant canonique d'un statut de passage. L'extension renvoie « run_status_passed »
     * (clé), d'autres versions « passed » (clé de couverture) ou « list_node.run_status.passed ».
     */
    public static function canonicalStatus(string $id): string
    {
        $normalized = strtolower((string) preg_replace('/^(list_node\.)?run_(native_)?status[._]/i', '', trim($id)));

        return match (str_replace([' ', '-', '_'], '', $normalized)) {
            'passed' => 'passed',
            'failed' => 'failed',
            'requiresattention', 'needsattention' => 'needsAttention',
            'planned' => 'planned',
            'skipped' => 'skipped',
            default => '' !== $normalized ? $normalized : 'unknown',
        };
    }

    /**
     * Applique canonicalStatus() aux groupes d'une couverture (données déjà enregistrées comprises).
     *
     * @param array<string, mixed>|null $coverage
     *
     * @return array<string, mixed>|null
     */
    public static function canonicalize(?array $coverage): ?array
    {
        if (null === $coverage || !isset($coverage['groups']) || !\is_array($coverage['groups'])) {
            return $coverage;
        }
        $merged = [];
        foreach ($coverage['groups'] as $g) {
            $id = self::canonicalStatus((string) ($g['id'] ?? ''));
            if (isset($merged[$id])) {
                $merged[$id]['count'] += (int) $g['count'];
            } else {
                $merged[$id] = ['id' => $id] + $g;
            }
        }
        $coverage['groups'] = array_values($merged);

        return $coverage;
    }

    public const HEALTH_LEVELS = [
        'failed' => ['label' => 'En échec', 'colour' => 'Red'],
        'warning' => ['label' => 'À surveiller', 'colour' => 'Yellow'],
        'passed' => ['label' => 'Conforme', 'colour' => 'Green'],
        'none' => ['label' => 'Non couvert', 'colour' => 'Grey'],
    ];

    /**
     * Code couleur d'une fiche : failed (au moins un échec), warning (à surveiller, ignoré,
     * planifié ou sans passage), passed (tout est passé), none (pas de test / pas d'entité).
     *
     * @param array<string, mixed>|null $coverage
     */
    public static function health(?array $coverage): string
    {
        $coverage = self::canonicalize($coverage);
        if (null === $coverage || 'hasData' !== ($coverage['status'] ?? null) || (int) ($coverage['totalTests'] ?? 0) <= 0) {
            return 'none';
        }
        $counts = array_column((array) ($coverage['groups'] ?? []), 'count', 'id');
        if (($counts['failed'] ?? 0) > 0) {
            return 'failed';
        }
        $passed = (int) ($counts['passed'] ?? 0);

        return $passed >= (int) $coverage['totalTests'] ? 'passed' : 'warning';
    }

    /**
     * Lignes du résumé : une par type de résultat, puis « Sans passage », avec pourcentage du total.
     *
     * @param array<string, mixed> $summary résultat de aggregate()
     *
     * @return list<array{id: string, name: string, count: int, percent: float}>
     */
    public static function summaryRows(array $summary): array
    {
        $total = max(1, (int) $summary['totalTests']);
        $rows = [];
        foreach ((array) $summary['groups'] as $g) {
            $rows[] = ['id' => (string) $g['id'], 'name' => (string) $g['name'], 'count' => (int) $g['count'], 'percent' => round(100 * $g['count'] / $total, 1)];
        }
        $notRun = (int) $summary['totalTests'] - (int) $summary['executedTests'];
        if ($notRun > 0) {
            $rows[] = ['id' => 'none', 'name' => 'Sans passage', 'count' => $notRun, 'percent' => round(100 * $notRun / $total, 1)];
        }

        return $rows;
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
