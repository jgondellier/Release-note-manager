<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Confluence\Dto\ConfluencePage;
use App\Service\Confluence\Dto\PagePublishResult;
use App\Service\Http\ExternalApiException;

/**
 * Crée ou met à jour une page Confluence sans jamais créer de doublon :
 *
 * 1. page déjà connue (identifiant enregistré) → mise à jour (version + 1) ;
 * 2. sinon, page de même titre dans l'espace → reprise et mise à jour ;
 * 3. sinon → création ;
 *
 * Lors d'une mise à jour, la page est (re)placée sous $parentId s'il est fourni
 * (ex. pages publiées avant l'introduction de la page de regroupement).
 *
 * En cas de conflit de version (409, modification concurrente dans Confluence),
 * la page est relue et la mise à jour retentée une fois.
 * Si la création échoue parce que le titre existe déjà, la page est retrouvée et mise à jour.
 */
class ConfluencePagePublisher
{
    public function __construct(private readonly ConfluenceApiResolver $resolver)
    {
    }

    public function publish(ExternalConnection $connection, string $spaceKey, ?string $parentId, ?string $knownPageId, string $title, string $storage): PagePublishResult
    {
        $api = $this->resolver->for($connection);

        $page = null;
        if (null !== $knownPageId) {
            $page = $api->getPage($connection, $knownPageId);
        }
        $page ??= $api->findPageByTitle($connection, $spaceKey, $title);

        if (null !== $page) {
            return new PagePublishResult($this->update($connection, $api, $page, $title, $storage, $parentId), PagePublishResult::UPDATED);
        }

        try {
            return new PagePublishResult($api->createPage($connection, $spaceKey, $parentId, $title, $storage), PagePublishResult::CREATED);
        } catch (ExternalApiException $e) {
            // Titre déjà pris (création concurrente ou page non indexée) : on met à jour la page existante.
            if (\in_array($e->getCategory(), [ExternalApiException::BAD_REQUEST, ExternalApiException::CONFLICT], true)
                && null !== ($existing = $api->findPageByTitle($connection, $spaceKey, $title))) {
                return new PagePublishResult($this->update($connection, $api, $existing, $title, $storage, $parentId), PagePublishResult::UPDATED);
            }
            throw $e;
        }
    }

    /**
     * Page de classement (ex. page d'année) : réutilisée telle quelle si une page de ce titre existe déjà
     * dans l'espace (contenu et emplacement laissés intacts), créée sous $parentId sinon.
     */
    public function ensurePage(ExternalConnection $connection, string $spaceKey, ?string $parentId, string $title, string $storage): PagePublishResult
    {
        $api = $this->resolver->for($connection);
        if (null !== $page = $api->findPageByTitle($connection, $spaceKey, $title)) {
            return new PagePublishResult($page, PagePublishResult::UNCHANGED);
        }
        try {
            return new PagePublishResult($api->createPage($connection, $spaceKey, $parentId, $title, $storage), PagePublishResult::CREATED);
        } catch (ExternalApiException $e) {
            // Création concurrente : la page existe désormais.
            if (\in_array($e->getCategory(), [ExternalApiException::BAD_REQUEST, ExternalApiException::CONFLICT], true)
                && null !== ($existing = $api->findPageByTitle($connection, $spaceKey, $title))) {
                return new PagePublishResult($existing, PagePublishResult::UNCHANGED);
            }
            throw $e;
        }
    }

    private function update(ExternalConnection $connection, ConfluenceApiInterface $api, ConfluencePage $page, string $title, string $storage, ?string $parentId): ConfluencePage
    {
        try {
            return $api->updatePage($connection, $page, $title, $storage, $parentId);
        } catch (ExternalApiException $e) {
            if (!$e->isConflict()) {
                throw $e;
            }
            $fresh = $api->getPage($connection, $page->id);
            if (null === $fresh) {
                throw $e;
            }

            return $api->updatePage($connection, $fresh, $title, $storage, $parentId);
        }
    }
}
