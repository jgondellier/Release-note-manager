<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Confluence\Dto\ConfluencePage;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;

/**
 * Confluence Server / Data Center — API REST v1 (<base>/rest/api/content).
 * L'URL de base inclut l'éventuel contexte (ex. https://confluence.interne/confluence).
 */
final class ConfluenceServerApi implements ConfluenceApiInterface
{
    public function __construct(private readonly AtlassianApiClient $client)
    {
    }

    public function supports(ExternalConnection $connection): bool
    {
        return $connection->isConfluence() && !$connection->isCloud();
    }

    public function testConnection(ExternalConnection $connection): string
    {
        $user = $this->client->get($connection, '/rest/api/user/current');
        if ('anonymous' === ($user['type'] ?? null) || (!isset($user['username']) && !isset($user['displayName']))) {
            throw new ExternalApiException('Confluence a traité la requête en utilisateur anonyme : authentification non prise en compte (méthode ou token incorrect).', ExternalApiException::AUTHENTICATION);
        }
        $spaces = $this->client->get($connection, '/rest/api/space', ['limit' => 5]);

        return sprintf('Connecté en tant que « %s » (%d espace(s) visible(s) sur la première page).', (string) ($user['displayName'] ?? $user['username']), \count((array) ($spaces['results'] ?? [])));
    }

    public function checkSpace(ExternalConnection $connection, string $spaceKey): string
    {
        try {
            $space = $this->client->get($connection, '/rest/api/space/' . rawurlencode($spaceKey));
        } catch (ExternalApiException $e) {
            if ($e->isNotFound()) {
                throw new ExternalApiException(sprintf('Espace Confluence « %s » introuvable ou inaccessible.', $spaceKey), ExternalApiException::NOT_FOUND, 404, $e);
            }
            throw $e;
        }

        return (string) ($space['name'] ?? $spaceKey);
    }

    public function getPage(ExternalConnection $connection, string $pageId): ?ConfluencePage
    {
        try {
            $data = $this->client->get($connection, '/rest/api/content/' . rawurlencode($pageId), ['expand' => 'version,space']);
        } catch (ExternalApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
        if ('trashed' === ($data['status'] ?? null)) {
            return null;
        }

        return $this->toPage($connection, $data);
    }

    public function findPageByTitle(ExternalConnection $connection, string $spaceKey, string $title): ?ConfluencePage
    {
        $data = $this->client->get($connection, '/rest/api/content', [
            'spaceKey' => $spaceKey,
            'title' => $title,
            'type' => 'page',
            'expand' => 'version',
            'limit' => 5,
        ]);
        foreach ((array) ($data['results'] ?? []) as $result) {
            if (\is_array($result) && ($result['title'] ?? null) === $title) {
                return $this->toPage($connection, $result);
            }
        }

        return null;
    }

    public function createPage(ExternalConnection $connection, string $spaceKey, ?string $parentId, string $title, string $storage): ConfluencePage
    {
        $body = [
            'type' => 'page',
            'title' => $title,
            'space' => ['key' => $spaceKey],
            'body' => ['storage' => ['value' => $storage, 'representation' => 'storage']],
        ];
        if (null !== $parentId) {
            $body['ancestors'] = [['id' => $parentId]];
        }

        return $this->toPage($connection, $this->client->post($connection, '/rest/api/content', $body));
    }

    public function updatePage(ExternalConnection $connection, ConfluencePage $current, string $title, string $storage, ?string $parentId = null): ConfluencePage
    {
        $body = [
            'id' => $current->id,
            'type' => 'page',
            'title' => $title,
            'body' => ['storage' => ['value' => $storage, 'representation' => 'storage']],
            'version' => ['number' => $current->version + 1, 'message' => 'Publication Release Note Manager'],
        ];
        if (null !== $parentId) {
            // API v1 : « ancestors » dans la mise à jour déplace la page sous ce parent.
            $body['ancestors'] = [['id' => $parentId]];
        }
        $data = $this->client->put($connection, '/rest/api/content/' . rawurlencode($current->id), $body);

        return $this->toPage($connection, $data);
    }

    /** @param array<mixed> $data */
    private function toPage(ExternalConnection $connection, array $data): ConfluencePage
    {
        if (!isset($data['id'])) {
            throw new ExternalApiException('Réponse Confluence sans identifiant de page.', ExternalApiException::INVALID_RESPONSE);
        }
        $webui = (string) ($data['_links']['webui'] ?? '');
        $base = (string) ($data['_links']['base'] ?? $connection->getBaseUrl());

        return new ConfluencePage(
            (string) $data['id'],
            (string) ($data['title'] ?? ''),
            (int) ($data['version']['number'] ?? 1),
            '' !== $webui ? $base . $webui : $connection->getBaseUrl() . '/pages/viewpage.action?pageId=' . rawurlencode((string) $data['id'])
        );
    }
}
