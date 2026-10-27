<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Entity\Project;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraIssue;
use App\Service\Jira\Dto\JiraVersion;
use Psr\Log\LoggerInterface;

/**
 * Recherche paginée des tickets Jira.
 *
 * - Cloud : POST /rest/api/3/search/jql, pagination par nextPageToken.
 * - Server/Data Center : GET /rest/api/2/search, pagination par startAt/total.
 */
class JiraIssueService
{
    public const PAGE_SIZE = 100;
    public const MAX_ISSUES = 5000;
    private const KEY_BATCH_SIZE = 50;

    public function __construct(
        private readonly AtlassianApiClient $client,
        private readonly JiraIssueNormalizer $normalizer,
        private readonly LoggerInterface $integrationLogger,
    ) {
    }

    /**
     * Tickets du projet portant la Fix Version sélectionnée, et uniquement ceux-là.
     * $withComments : commentaires lus dans la même requête (évite un appel par ticket).
     *
     * @return list<JiraIssue>
     */
    public function findByFixVersion(Project $project, JiraVersion $version, bool $withComments = false): array
    {
        $connection = $this->connection($project);
        $jql = JiraJqlBuilder::forFixVersion($project->getJiraProjectKey(), $version->id);
        $issues = $this->search($connection, $jql, $withComments ? ['comment'] : []);

        // Contrôle défensif : on écarte tout ticket qui ne porte pas la version demandée.
        $filtered = array_values(array_filter($issues, static fn (JiraIssue $i) => $i->hasFixVersion($version->id)));
        if (\count($filtered) !== \count($issues)) {
            $this->integrationLogger->warning('Tickets hors version écartés', [
                'project' => $project->getCode(),
                'version_id' => $version->id,
                'discarded' => \count($issues) - \count($filtered),
            ]);
        }

        return $filtered;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, JiraIssue> indexé par clé Jira (les clés inaccessibles sont absentes)
     */
    public function findByKeys(ExternalConnection $connection, array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, JiraJqlBuilder::isValidIssueKey(...))));
        $result = [];
        foreach (array_chunk($keys, self::KEY_BATCH_SIZE) as $chunk) {
            try {
                $issues = $this->search($connection, JiraJqlBuilder::forKeys($chunk));
            } catch (ExternalApiException $e) {
                // Jira renvoie 400 si une clé du lot n'existe plus : on bascule en appels unitaires.
                if (ExternalApiException::BAD_REQUEST !== $e->getCategory()) {
                    throw $e;
                }
                $issues = [];
                foreach ($chunk as $key) {
                    $issue = $this->getIssue($connection, $key);
                    if (null !== $issue) {
                        $issues[] = $issue;
                    }
                }
            }
            foreach ($issues as $issue) {
                $result[$issue->key] = $issue;
            }
        }

        return $result;
    }

    /** Ticket unique, ou null s'il est introuvable / inaccessible. */
    public function getIssue(ExternalConnection $connection, string $key): ?JiraIssue
    {
        JiraApi::assertJira($connection);
        if (!JiraJqlBuilder::isValidIssueKey($key)) {
            throw new \InvalidArgumentException(sprintf('Clé de ticket invalide : %s', $key));
        }
        try {
            $data = $this->client->get(
                $connection,
                sprintf('%s/issue/%s', JiraApi::prefix($connection), rawurlencode(strtoupper($key))),
                ['fields' => implode(',', JiraIssueNormalizer::fields($connection))]
            );
        } catch (ExternalApiException $e) {
            if ($e->isNotFound() || ExternalApiException::FORBIDDEN === $e->getCategory()) {
                return null;
            }
            throw $e;
        }

        return $this->normalizer->normalize($data, $connection);
    }

    /**
     * Recherche JQL paginée.
     *
     * @param list<string> $extraFields champs demandés en plus des champs standard (ex. comment)
     *
     * @return list<JiraIssue>
     */
    public function search(ExternalConnection $connection, string $jql, array $extraFields = []): array
    {
        JiraApi::assertJira($connection);
        $fields = array_values(array_unique([...JiraIssueNormalizer::fields($connection), ...$extraFields]));

        return $connection->isCloud()
            ? $this->searchCloud($connection, $jql, $fields)
            : $this->searchServer($connection, $jql, $fields);
    }

    /**
     * @param list<string> $fields
     *
     * @return list<JiraIssue>
     */
    private function searchCloud(ExternalConnection $connection, string $jql, array $fields): array
    {
        $issues = [];
        $token = null;
        $pages = 0;
        do {
            $body = [
                'jql' => $jql,
                'fields' => $fields,
                'maxResults' => self::PAGE_SIZE,
            ];
            if (null !== $token) {
                $body['nextPageToken'] = $token;
            }
            $data = $this->client->post($connection, '/rest/api/3/search/jql', $body);
            $this->collect($data, $connection, $issues);

            $token = isset($data['nextPageToken']) && \is_string($data['nextPageToken']) && '' !== $data['nextPageToken'] ? $data['nextPageToken'] : null;
            $isLast = (bool) ($data['isLast'] ?? (null === $token));
            ++$pages;
            $this->guard(\count($issues), $pages);
        } while (!$isLast && null !== $token);

        return $issues;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<JiraIssue>
     */
    private function searchServer(ExternalConnection $connection, string $jql, array $fields): array
    {
        $issues = [];
        $startAt = 0;
        $pages = 0;
        do {
            // GET + paramètres d'URL : forme acceptée par toutes les versions de Jira Server/DC
            // (le corps POST et l'option validateQuery varient selon les versions).
            $data = $this->client->get($connection, '/rest/api/2/search', [
                'jql' => $jql,
                'fields' => implode(',', $fields),
                'startAt' => $startAt,
                'maxResults' => self::PAGE_SIZE,
            ]);
            $received = $this->collect($data, $connection, $issues);
            $total = (int) ($data['total'] ?? 0);
            // L'instance peut plafonner maxResults : on avance du nombre réellement reçu.
            $startAt += $received;
            ++$pages;
            $this->guard(\count($issues), $pages);
        } while ($received > 0 && $startAt < $total);

        return $issues;
    }

    /**
     * @param array<mixed>    $data
     * @param list<JiraIssue> $issues
     */
    private function collect(array $data, ExternalConnection $connection, array &$issues): int
    {
        if (!isset($data['issues']) || !\is_array($data['issues'])) {
            throw new ExternalApiException('Réponse de recherche Jira invalide (liste « issues » absente).', ExternalApiException::INVALID_RESPONSE);
        }
        $count = 0;
        foreach ($data['issues'] as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            try {
                $issues[] = $this->normalizer->normalize($raw, $connection);
                ++$count;
            } catch (\UnexpectedValueException) {
                $this->integrationLogger->warning('Ticket Jira ignoré (réponse incomplète)', ['connection_id' => $connection->getId()]);
            }
        }

        return $count;
    }

    private function guard(int $count, int $pages): void
    {
        if ($count > self::MAX_ISSUES || $pages > (int) ceil(self::MAX_ISSUES / 10)) {
            throw new ExternalApiException(sprintf('Plus de %d tickets correspondent à cette version : import interrompu par sécurité.', self::MAX_ISSUES), ExternalApiException::INVALID_RESPONSE);
        }
    }

    private function connection(Project $project): ExternalConnection
    {
        $connection = $project->getJiraConnection();
        if (null === $connection) {
            throw ExternalApiException::configuration(sprintf('Aucune connexion Jira n\'est associée au projet « %s ».', $project->getName()));
        }
        JiraApi::assertJira($connection);

        return $connection;
    }
}
