<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Confluence\Dto\ConfluencePage;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;

/**
 * Confluence Cloud — API REST v2 (https://<site>.atlassian.net/wiki/api/v2).
 * L'URL de base de la connexion peut être le site (…atlassian.net) ou …/wiki.
 */
final class ConfluenceCloudApi implements ConfluenceApiInterface
{
    /** @var array<string, string> cache clé d'espace → identifiant v2 */
    private array $spaceIds = [];

    public function __construct(private readonly AtlassianApiClient $client)
    {
    }

    public function supports(ExternalConnection $connection): bool
    {
        return $connection->isConfluence() && $connection->isCloud();
    }

    public function testConnection(ExternalConnection $connection): string
    {
        $data = $this->client->get($connection, $this->path($connection, '/api/v2/spaces'), ['limit' => 5]);
        if (!isset($data['results']) || !\is_array($data['results'])) {
            throw new ExternalApiException('Réponse inattendue de Confluence Cloud (liste des espaces).', ExternalApiException::INVALID_RESPONSE);
        }

        return sprintf('Accès à Confluence Cloud validé (%s espace(s) visible(s) sur la première page).', \count($data['results']));
    }

    public function checkSpace(ExternalConnection $connection, string $spaceKey): string
    {
        $space = $this->space($connection, $spaceKey);

        return (string) ($space['name'] ?? $spaceKey);
    }

    public function getPage(ExternalConnection $connection, string $pageId): ?ConfluencePage
    {
        try {
            $data = $this->client->get($connection, $this->path($connection, '/api/v2/pages/' . rawurlencode($pageId)));
        } catch (ExternalApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
        if ('trashed' === ($data['status'] ?? null) || 'deleted' === ($data['status'] ?? null)) {
            return null;
        }

        return $this->toPage($connection, $data);
    }

    public function findPageByTitle(ExternalConnection $connection, string $spaceKey, string $title): ?ConfluencePage
    {
        $spaceId = $this->spaceId($connection, $spaceKey);
        $data = $this->client->get($connection, $this->path($connection, '/api/v2/pages'), [
            'space-id' => $spaceId,
            'title' => $title,
            'status' => 'current',
            'limit' => 5,
        ]);
        foreach ((array) ($data['results'] ?? []) as $result) {
            if (\is_array($result) && ($result['title'] ?? null) === $title) {
                // La liste ne renvoie pas toujours la version : on relit la page.
                return $this->getPage($connection, (string) $result['id']);
            }
        }

        return null;
    }

    public function createPage(ExternalConnection $connection, string $spaceKey, ?string $parentId, string $title, string $storage): ConfluencePage
    {
        $body = [
            'spaceId' => $this->spaceId($connection, $spaceKey),
            'status' => 'current',
            'title' => $title,
            'body' => ['representation' => 'storage', 'value' => $storage],
        ];
        if (null !== $parentId) {
            $body['parentId'] = $parentId;
        }

        return $this->toPage($connection, $this->client->post($connection, $this->path($connection, '/api/v2/pages'), $body));
    }

    public function updatePage(ExternalConnection $connection, ConfluencePage $current, string $title, string $storage): ConfluencePage
    {
        $data = $this->client->put($connection, $this->path($connection, '/api/v2/pages/' . rawurlencode($current->id)), [
            'id' => $current->id,
            'status' => 'current',
            'title' => $title,
            'body' => ['representation' => 'storage', 'value' => $storage],
            'version' => ['number' => $current->version + 1, 'message' => 'Publication Release Note Manager'],
        ]);

        return $this->toPage($connection, $data);
    }

    /** @return array<string, mixed> */
    private function space(ExternalConnection $connection, string $spaceKey): array
    {
        $data = $this->client->get($connection, $this->path($connection, '/api/v2/spaces'), ['keys' => $spaceKey, 'limit' => 1]);
        foreach ((array) ($data['results'] ?? []) as $space) {
            if (\is_array($space) && isset($space['id']) && 0 === strcasecmp((string) ($space['key'] ?? ''), $spaceKey)) {
                return $space;
            }
        }

        throw new ExternalApiException(sprintf('Espace Confluence Cloud « %s » introuvable ou inaccessible.', $spaceKey), ExternalApiException::NOT_FOUND, 404);
    }

    private function spaceId(ExternalConnection $connection, string $spaceKey): string
    {
        $cacheKey = $connection->getId() . ':' . $spaceKey;

        return $this->spaceIds[$cacheKey] ??= (string) $this->space($connection, $spaceKey)['id'];
    }

    /** @param array<mixed> $data */
    private function toPage(ExternalConnection $connection, array $data): ConfluencePage
    {
        if (!isset($data['id'])) {
            throw new ExternalApiException('Réponse Confluence Cloud sans identifiant de page.', ExternalApiException::INVALID_RESPONSE);
        }
        $webui = (string) ($data['_links']['webui'] ?? '');
        $base = (string) ($data['_links']['base'] ?? self::wikiBase($connection));

        return new ConfluencePage(
            (string) $data['id'],
            (string) ($data['title'] ?? ''),
            (int) ($data['version']['number'] ?? 1),
            '' !== $webui ? $base . $webui : self::wikiBase($connection) . '/pages/viewpage.action?pageId=' . rawurlencode((string) $data['id'])
        );
    }

    private function path(ExternalConnection $connection, string $apiPath): string
    {
        return str_ends_with(strtolower($connection->getBaseUrl()), '/wiki') ? $apiPath : '/wiki' . $apiPath;
    }

    public static function wikiBase(ExternalConnection $connection): string
    {
        $base = $connection->getBaseUrl();

        return str_ends_with(strtolower($base), '/wiki') ? $base : $base . '/wiki';
    }
}
