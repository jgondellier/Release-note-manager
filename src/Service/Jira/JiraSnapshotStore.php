<?php

declare(strict_types=1);

namespace App\Service\Jira;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mémorise brièvement les données Jira lues pour un écran d'aperçu (import, actualisation) afin que la
 * confirmation applique exactement ce qui a été montré, sans relire Jira. Usage unique, durée limitée
 * (pool cache.jira_snapshot) : passé ce délai, la confirmation relit Jira.
 */
class JiraSnapshotStore
{
    public function __construct(
        #[Autowire(service: 'cache.jira_snapshot')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function save(string $key, mixed $data): void
    {
        $this->pool->save($this->pool->getItem(self::id($key))->set($data));
    }

    /** Retourne puis oublie la donnée mémorisée (null si absente ou expirée). */
    public function take(string $key): mixed
    {
        $item = $this->pool->getItem(self::id($key));
        if (!$item->isHit()) {
            return null;
        }
        $this->pool->deleteItem(self::id($key));

        return $item->get();
    }

    private static function id(string $key): string
    {
        return 'jira_snapshot_' . hash('sha256', $key);
    }
}
