<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;

/**
 * Teste une connexion Jira (authentification + droits de lecture) et, sur
 * Server/Data Center, détecte l'identifiant du champ « Epic Link ».
 */
class JiraConnectionTester
{
    private const EPIC_LINK_SCHEMA = 'com.pyxis.greenhopper.jira:gh-epic-link';

    public function __construct(private readonly AtlassianApiClient $client)
    {
    }

    /**
     * @return string message de succès
     *
     * @throws ExternalApiException
     */
    public function test(ExternalConnection $connection): string
    {
        JiraApi::assertJira($connection);
        $prefix = JiraApi::prefix($connection);

        $me = $this->client->get($connection, $prefix . '/myself');
        $who = (string) ($me['displayName'] ?? $me['name'] ?? $me['emailAddress'] ?? '');
        if ('' === $who) {
            throw new ExternalApiException('Jira a répondu sans identifier l\'utilisateur : authentification non prise en compte.', ExternalApiException::AUTHENTICATION);
        }

        $server = $this->client->get($connection, $prefix . '/serverInfo');
        $version = (string) ($server['version'] ?? '?');
        $deployment = (string) ($server['deploymentType'] ?? '');

        $message = sprintf('Connecté en tant que « %s » (Jira %s%s).', $who, $version, '' !== $deployment ? ', ' . $deployment : '');

        if ($connection->isCloud() && 'Cloud' !== $deployment && '' !== $deployment) {
            $message .= ' Attention : l\'instance ne se déclare pas « Cloud », vérifiez le type choisi.';
        }

        if (!$connection->isCloud()) {
            $detected = $this->detectEpicLinkField($connection);
            if (null === $connection->getJiraEpicLinkField() && null !== $detected) {
                $connection->setJiraEpicLinkField($detected);
                $message .= sprintf(' Champ Epic Link détecté et enregistré : %s.', $detected);
            } elseif (null !== $detected && $detected !== $connection->getJiraEpicLinkField()) {
                $message .= sprintf(' Attention : le champ Epic Link de l\'instance semble être %s (configuré : %s).', $detected, $connection->getJiraEpicLinkField());
            } elseif (null === $detected && null === $connection->getJiraEpicLinkField()) {
                $message .= ' Aucun champ Epic Link détecté : seule la relation parent sera utilisée.';
            }
        }

        return $message;
    }

    /** Vérifie l'accès à un projet Jira. */
    public function checkProject(ExternalConnection $connection, string $projectKey): string
    {
        $data = $this->client->get($connection, sprintf('%s/project/%s', JiraApi::prefix($connection), rawurlencode($projectKey)));

        return sprintf('Projet Jira %s accessible (« %s »).', $projectKey, (string) ($data['name'] ?? $projectKey));
    }

    private function detectEpicLinkField(ExternalConnection $connection): ?string
    {
        try {
            $fields = $this->client->get($connection, '/rest/api/2/field');
        } catch (ExternalApiException) {
            return null;
        }
        foreach ($fields as $field) {
            if (\is_array($field) && self::EPIC_LINK_SCHEMA === ($field['schema']['custom'] ?? null) && isset($field['id'])) {
                return (string) $field['id'];
            }
        }

        return null;
    }
}
