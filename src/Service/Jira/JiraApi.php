<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Service\Http\ExternalApiException;

/**
 * Différences d'API entre Jira Cloud et Jira Server/Data Center.
 */
final class JiraApi
{
    public static function prefix(ExternalConnection $connection): string
    {
        // Cloud : API v3 (descriptions en ADF). Server/DC : API v2 (descriptions texte/wiki).
        return $connection->isCloud() ? '/rest/api/3' : '/rest/api/2';
    }

    public static function assertJira(ExternalConnection $connection): void
    {
        if (!$connection->isJira()) {
            throw ExternalApiException::configuration(sprintf('La connexion « %s » n\'est pas une connexion Jira.', $connection->getName()));
        }
    }

    public static function browseUrl(ExternalConnection $connection, string $key): string
    {
        return $connection->getBaseUrl() . '/browse/' . rawurlencode($key);
    }
}
