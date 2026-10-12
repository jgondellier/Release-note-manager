<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\Project;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraVersion;

/**
 * Versions (Fix Versions) d'un projet Jira.
 */
class JiraVersionService
{
    public function __construct(private readonly AtlassianApiClient $client)
    {
    }

    /**
     * Versions du projet : non livrées d'abord, puis livrées de la plus récente à la plus ancienne.
     *
     * @return list<JiraVersion>
     */
    public function listVersions(Project $project, bool $includeArchived = false): array
    {
        $connection = $this->connection($project);
        $path = sprintf('%s/project/%s/versions', JiraApi::prefix($connection), rawurlencode($project->getJiraProjectKey()));

        try {
            $data = $this->client->get($connection, $path);
        } catch (ExternalApiException $e) {
            if ($e->isNotFound()) {
                throw new ExternalApiException(sprintf('Projet Jira « %s » introuvable ou inaccessible avec cette connexion.', $project->getJiraProjectKey()), ExternalApiException::NOT_FOUND, 404, $e);
            }
            throw $e;
        }
        if (!array_is_list($data)) {
            throw new ExternalApiException('Réponse inattendue de Jira pour la liste des versions.', ExternalApiException::INVALID_RESPONSE);
        }

        $versions = [];
        foreach ($data as $item) {
            if (!\is_array($item)) {
                continue;
            }
            try {
                $version = JiraVersion::fromApi($item);
            } catch (\UnexpectedValueException) {
                continue;
            }
            if ($version->archived && !$includeArchived) {
                continue;
            }
            $versions[] = $version;
        }

        usort($versions, static function (JiraVersion $a, JiraVersion $b): int {
            if ($a->released !== $b->released) {
                return $a->released ? 1 : -1;
            }
            $da = $a->releaseDate?->getTimestamp() ?? PHP_INT_MAX;
            $db = $b->releaseDate?->getTimestamp() ?? PHP_INT_MAX;

            return $a->released ? $db <=> $da : $da <=> $db;
        });

        return $versions;
    }

    /**
     * Retourne la version demandée en garantissant qu'elle appartient bien au projet.
     */
    public function getVersion(Project $project, string $versionId): JiraVersion
    {
        foreach ($this->listVersions($project, true) as $version) {
            if ($version->id === $versionId) {
                return $version;
            }
        }

        throw new ExternalApiException(sprintf('La version Jira n°%s n\'existe pas (ou plus) dans le projet %s.', $versionId, $project->getJiraProjectKey()), ExternalApiException::NOT_FOUND, 404);
    }

    private function connection(Project $project): \App\Entity\ExternalConnection
    {
        $connection = $project->getJiraConnection();
        if (null === $connection) {
            throw ExternalApiException::configuration(sprintf('Aucune connexion Jira n\'est associée au projet « %s ».', $project->getName()));
        }
        JiraApi::assertJira($connection);

        return $connection;
    }
}
