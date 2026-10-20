<?php

declare(strict_types=1);

namespace App\Service\Publication;

use App\Entity\Publication;
use App\Entity\PublicationAttempt;
use App\Entity\PublicationContainer;
use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Enum\PublicationStatus;
use App\Enum\PublicationTarget;
use App\Enum\ReleaseStatus;
use App\Entity\ExternalConnection;
use App\Service\Confluence\ConfluenceApiResolver;
use App\Service\Confluence\ConfluencePagePublisher;
use App\Service\Confluence\Dto\PagePublishResult;
use App\Service\Http\ExternalApiException;
use App\Service\Publication\Dto\PublicationOutcome;
use App\Service\Publication\Dto\PublicationTargetRow;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Publication des trois formats (et du PV de recette s'il existe) vers Confluence Cloud
 * et Server/DC (jusqu'à 8 cibles).
 *
 * - Chaque cible est traitée indépendamment : une erreur n'empêche pas les autres.
 * - Un verrou par (release, format, cible) + le statut « en cours » empêchent les
 *   publications concurrentes de la même cible.
 * - Le mapping Publication (release, format, cible) conserve l'identifiant de page :
 *   une republication met à jour la même page.
 * - Chaque tentative est historisée (PublicationAttempt) sans aucun secret.
 * - Sur chaque instance, les trois pages sont rangées sous une page de regroupement
 *   de la release (PublicationContainer), elle-même placée sous la page parente du projet.
 */
class PublicationService
{
    public function __construct(
        private readonly ReleaseNoteRenderer $renderer,
        private readonly ConfluencePagePublisher $publisher,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $publicationLogger,
        private readonly ConfluenceApiResolver $apiResolver,
    ) {
    }

    /** @var array<string, true> pages de regroupement déjà vérifiées pendant cette exécution */
    private array $verifiedContainers = [];

    /** @return list<PublicationTargetRow> les 6 combinaisons possibles, disponibles ou non */
    public function getTargetMatrix(Release $release): array
    {
        $project = $release->getProject();
        \assert(null !== $project);
        $rows = [];
        $formats = PublicationFormat::releaseNoteFormats();
        if (null !== $release->getAcceptanceReport()) {
            $formats[] = PublicationFormat::AcceptanceReport;
        }
        foreach ($formats as $format) {
            $title = $this->renderer->pageTitle($release, $format);
            foreach (PublicationTarget::cases() as $target) {
                $connection = $project->getConfluenceConnection($target);
                $reason = null;
                if (null === $connection || null === $project->getConfluenceSpaceKey($target)) {
                    $reason = 'Non configurée pour ce projet';
                } elseif (!$connection->isActive()) {
                    $reason = sprintf('Connexion « %s » désactivée', $connection->getName());
                } elseif ($release->isArchived()) {
                    $reason = 'Release archivée';
                }
                $publication = $release->findPublication($format, $target);
                $dedicatedParent = PublicationFormat::AcceptanceReport === $format ? $project->getConfluenceAcceptanceParentPageId($target) : null;
                $rows[] = new PublicationTargetRow(
                    format: $format,
                    target: $target,
                    available: null === $reason,
                    unavailableReason: $reason,
                    connectionName: $connection?->getName(),
                    spaceKey: $project->getConfluenceSpaceKey($target),
                    parentPageId: $dedicatedParent ?? $project->getConfluenceParentPageId($target),
                    pageTitle: $title,
                    containerTitle: $this->renderer->containerTitle($release),
                    container: $release->findContainer($target),
                    publication: $publication,
                    outdated: null !== $publication && null !== $publication->getContentHash() && $this->isOutdated($release, $format, $publication),
                    useContainer: null === $dedicatedParent,
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $targetKeys clés « format:cible » (ex. « it:cloud »)
     *
     * @return list<PublicationOutcome>
     */
    public function publish(Release $release, array $targetKeys): array
    {
        $rows = [];
        foreach ($this->getTargetMatrix($release) as $row) {
            $rows[$row->key()] = $row;
        }

        $outcomes = [];
        foreach (array_unique($targetKeys) as $key) {
            $row = $rows[$key] ?? null;
            if (null === $row) {
                continue;
            }
            if (!$row->available) {
                $outcomes[] = new PublicationOutcome($row->format, $row->target, PublicationOutcome::SKIPPED, (string) $row->unavailableReason);
                continue;
            }
            $outcomes[] = $this->publishOne($release, $row);
        }

        $this->refreshReleaseStatus($release);
        $this->em->flush();

        return $outcomes;
    }

    public function retry(Publication $publication): PublicationOutcome
    {
        $release = $publication->getRelease();
        \assert(null !== $release);
        $key = PublicationTargetRow::buildKey($publication->getFormat(), $publication->getTarget());
        $outcomes = $this->publish($release, [$key]);

        return $outcomes[0] ?? new PublicationOutcome($publication->getFormat(), $publication->getTarget(), PublicationOutcome::SKIPPED, 'Cible indisponible.');
    }

    private function publishOne(Release $release, PublicationTargetRow $row): PublicationOutcome
    {
        $project = $release->getProject();
        \assert(null !== $project);
        $connection = $project->getConfluenceConnection($row->target);
        \assert(null !== $connection && null !== $row->spaceKey);

        $lock = $this->lockFactory->createLock(sprintf('publication-%d-%s', (int) $release->getId(), str_replace(':', '-', $row->key())), Publication::STALE_AFTER_SECONDS);
        if (!$lock->acquire(false)) {
            return new PublicationOutcome($row->format, $row->target, PublicationOutcome::SKIPPED, 'Une publication de cette cible est déjà en cours.');
        }

        try {
            $publication = $release->findPublication($row->format, $row->target);
            if (null === $publication) {
                $publication = new Publication($row->format, $row->target);
                $release->addPublication($publication);
                $this->em->persist($publication);
            } elseif ($publication->isRunning()) {
                return new PublicationOutcome($row->format, $row->target, PublicationOutcome::SKIPPED, 'Une publication de cette cible est déjà en cours.');
            }

            $publication->markStarted($row->spaceKey, $row->pageTitle);
            $this->em->flush();
            $started = microtime(true);

            try {
                $parentId = $row->useContainer ? $this->ensureContainer($release, $row, $connection) : $row->parentPageId;
                $storage = $this->renderer->renderStorage($release, $row->format);
                $result = $this->publisher->publish(
                    $connection,
                    $row->spaceKey,
                    $parentId,
                    $publication->getConfluencePageId(),
                    $row->pageTitle,
                    $storage
                );
                $publication->markSucceeded($result->page->id, $result->page->url, $result->page->version, $this->renderer->contentHash($row->pageTitle, $storage));
                $message = sprintf('%s (version %d).', PagePublishResult::CREATED === $result->action ? 'Page créée' : 'Page mise à jour', $result->page->version);
                $publication->addAttempt(new PublicationAttempt(PublicationStatus::Success, $result->action, $message, $result->page->version, $this->elapsed($started)));
                $this->em->flush();
                $this->publicationLogger->info('Publication réussie', $this->context($release, $row) + ['page_id' => $result->page->id, 'action' => $result->action]);

                return new PublicationOutcome($row->format, $row->target, PublicationOutcome::SUCCESS, $message, $result->page->url);
            } catch (ExternalApiException $e) {
                $message = $e->getMessage();
                if ($e->isNotFound() && null !== $publication->getConfluencePageId()) {
                    $message .= ' La page parente ou l\'espace a peut-être été supprimé.';
                }

                return $this->fail($release, $row, $publication, $message, $started, $e);
            } catch (\Throwable $e) {
                // Erreur inattendue : message générique côté utilisateur, détail technique dans les journaux.
                return $this->fail($release, $row, $publication, 'Erreur interne lors de la publication (voir les journaux applicatifs).', $started, $e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Garantit l'existence de la page de regroupement de la release sur l'instance et
     * retourne son identifiant. Elle n'est republiée que si elle a disparu ou si son
     * contenu a changé (pas de nouvelle version Confluence à chaque publication).
     */
    private function ensureContainer(Release $release, PublicationTargetRow $row, ExternalConnection $connection): string
    {
        $key = $release->getId() . ':' . $row->target->value;
        $container = $release->findContainer($row->target);
        $title = $this->renderer->containerTitle($release);
        $storage = $this->renderer->renderContainerStorage($release, $row->target);
        $hash = $this->renderer->contentHash($title, $storage);

        if (null !== $container && isset($this->verifiedContainers[$key]) && $container->getContentHash() === $hash) {
            return $container->getConfluencePageId();
        }

        $lock = $this->lockFactory->createLock('publication-container-' . str_replace(':', '-', $key), Publication::STALE_AFTER_SECONDS);
        $lock->acquire(true);
        try {
            if (null !== $container && $container->getContentHash() === $hash
                && null !== $this->apiResolver->for($connection)->getPage($connection, $container->getConfluencePageId())) {
                $this->verifiedContainers[$key] = true;

                return $container->getConfluencePageId();
            }

            $result = $this->publisher->publish(
                $connection,
                (string) $row->spaceKey,
                $row->parentPageId,
                $container?->getConfluencePageId(),
                $title,
                $storage
            );
            if (null === $container) {
                $container = new PublicationContainer($row->target, $result->page->id, $result->page->url, $title, $result->page->version, $hash);
                $release->addContainer($container);
                $this->em->persist($container);
            } else {
                $container->update($result->page->id, $result->page->url, $title, $result->page->version, $hash);
            }
            $this->em->flush();
            $this->verifiedContainers[$key] = true;
            $this->publicationLogger->info('Page de regroupement publiée', $this->context($release, $row) + ['page_id' => $result->page->id, 'action' => $result->action]);

            return $result->page->id;
        } catch (ExternalApiException $e) {
            throw new ExternalApiException('Page de regroupement « ' . $title . ' » : ' . $e->getMessage(), $e->getCategory(), $e->getHttpStatus(), $e);
        } finally {
            $lock->release();
        }
    }

    private function fail(Release $release, PublicationTargetRow $row, Publication $publication, string $message, float $started, \Throwable $e): PublicationOutcome
    {
        $publication->markFailed($message);
        $publication->addAttempt(new PublicationAttempt(PublicationStatus::Failed, PublicationAttempt::ACTION_FAILED, $message, null, $this->elapsed($started)));
        $this->em->flush();
        $this->publicationLogger->error('Publication en échec', $this->context($release, $row) + [
            'error' => $e::class,
            'category' => $e instanceof ExternalApiException ? $e->getCategory() : null,
            'http_status' => $e instanceof ExternalApiException ? $e->getHttpStatus() : null,
            'detail' => $e instanceof ExternalApiException ? $e->getMessage() : mb_substr($e->getMessage(), 0, 300),
        ]);

        return new PublicationOutcome($row->format, $row->target, PublicationOutcome::FAILED, $message);
    }

    /**
     * Publiée : toutes les cibles configurées ont une publication réussie.
     * Partiellement publiée : au moins une. Sinon le statut éditorial est conservé.
     */
    public function refreshReleaseStatus(Release $release): void
    {
        if ($release->isArchived()) {
            return;
        }
        $project = $release->getProject();
        \assert(null !== $project);
        $configured = 0;
        $published = 0;
        // Le statut de la release ne dépend que des trois release notes (le PV est suivi à part).
        foreach (PublicationFormat::releaseNoteFormats() as $format) {
            foreach ($project->getConfiguredTargets() as $target) {
                ++$configured;
                if (null !== $release->findPublication($format, $target)?->getPublishedAt()) {
                    ++$published;
                }
            }
        }
        if ($published > 0 && $published === $configured) {
            $release->setStatus(ReleaseStatus::Published);
        } elseif ($published > 0) {
            $release->setStatus(ReleaseStatus::PartiallyPublished);
        }
    }

    private function isOutdated(Release $release, PublicationFormat $format, Publication $publication): bool
    {
        try {
            $title = $this->renderer->pageTitle($release, $format);

            return $this->renderer->contentHash($title, $this->renderer->renderStorage($release, $format)) !== $publication->getContentHash();
        } catch (\RuntimeException) {
            return true;
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** @return array<string, mixed> */
    private function context(Release $release, PublicationTargetRow $row): array
    {
        return [
            'release_id' => $release->getId(),
            'format' => $row->format->value,
            'target' => $row->target->value,
            'space' => $row->spaceKey,
        ];
    }
}
