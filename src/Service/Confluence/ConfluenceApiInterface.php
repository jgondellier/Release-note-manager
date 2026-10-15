<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Confluence\Dto\ConfluencePage;
use App\Service\Http\ExternalApiException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Opérations Confluence nécessaires à la publication. Deux implémentations :
 * Cloud (REST v2, /wiki/api/v2) et Server/Data Center (REST v1, /rest/api).
 */
#[AutoconfigureTag]
interface ConfluenceApiInterface
{
    public function supports(ExternalConnection $connection): bool;

    /** @throws ExternalApiException */
    public function testConnection(ExternalConnection $connection): string;

    /** @return string nom de l'espace ; ExternalApiException NOT_FOUND s'il n'existe pas */
    public function checkSpace(ExternalConnection $connection, string $spaceKey): string;

    /** @return ConfluencePage|null null si la page n'existe plus (404) */
    public function getPage(ExternalConnection $connection, string $pageId): ?ConfluencePage;

    public function findPageByTitle(ExternalConnection $connection, string $spaceKey, string $title): ?ConfluencePage;

    public function createPage(ExternalConnection $connection, string $spaceKey, ?string $parentId, string $title, string $storage): ConfluencePage;

    /** @param string|null $parentId si fourni, la page est (re)placée sous ce parent */
    public function updatePage(ExternalConnection $connection, ConfluencePage $current, string $title, string $storage, ?string $parentId = null): ConfluencePage;
}
