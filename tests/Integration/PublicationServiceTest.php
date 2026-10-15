<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Publication;
use App\Entity\PublicationAttempt;
use App\Entity\Release;
use App\Enum\PublicationFormat;
use App\Enum\PublicationStatus;
use App\Enum\PublicationTarget;
use App\Enum\ReleaseStatus;
use App\Service\Publication\Dto\PublicationOutcome;
use App\Service\Publication\PublicationService;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeAtlassianServer;
use App\Tests\Support\JiraFixtures;

final class PublicationServiceTest extends DatabaseTestCase
{
    private bool $serverDown = false;
    private int $nextId = 5000;
    /** @var array<string, array<string, array<string, mixed>>> pages simulées, par instance (cloud / server) */
    private array $stores = ['cloud' => [], 'server' => []];

    private function prepare(): Release
    {
        $project = $this->createProject();
        $issues = [JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée')];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');

        // Confluence Cloud (v2).
        $this->server
            ->on('GET', '/wiki/api/v2/spaces', static fn () => ['results' => [['id' => '777', 'key' => 'CCS']]])
            ->on('GET', '/wiki/api/v2/pages', fn (array $b, string $u, array $q) => ['results' => $this->findByTitle('cloud', $q['title'])])
            ->on('GET', '/wiki/api/v2/pages/\d+', fn (array $b, string $url) => $this->page('cloud', basename(parse_url($url, PHP_URL_PATH))))
            ->on('POST', '/wiki/api/v2/pages', fn (array $b) => $this->create('cloud', $b['title'], $b['parentId'] ?? null))
            ->on('PUT', '/wiki/api/v2/pages/\d+', fn (array $b) => $this->update('cloud', $b['id'], $b['version']['number'], $b['parentId'] ?? null));
        // Confluence Server (v1), éventuellement en panne.
        $this->server
            ->on('GET', '/confluence/rest/api/content', fn (array $b, string $u, array $q) => $this->serverDown ? FakeAtlassianServer::json([], 503) : ['results' => $this->findByTitle('server', $q['title'])])
            ->on('GET', '/confluence/rest/api/content/\d+', fn (array $b, string $url) => $this->page('server', basename(parse_url($url, PHP_URL_PATH))))
            ->on('POST', '/confluence/rest/api/content', fn (array $b) => $this->serverDown ? FakeAtlassianServer::json([], 503) : $this->create('server', $b['title'], $b['ancestors'][0]['id'] ?? null))
            ->on('PUT', '/confluence/rest/api/content/\d+', fn (array $b) => $this->update('server', $b['id'], $b['version']['number'], $b['ancestors'][0]['id'] ?? null));

        return $release;
    }

    private function findByTitle(string $instance, string $title): array
    {
        return array_values(array_filter($this->stores[$instance], static fn ($p) => $p['title'] === $title));
    }

    private function page(string $instance, string $id): mixed
    {
        return isset($this->stores[$instance][$id]) ? $this->stores[$instance][$id] + ['status' => 'current', '_links' => ['webui' => '/p/' . $id]] : FakeAtlassianServer::json([], 404);
    }

    private function create(string $instance, string $title, ?string $parentId = null): array
    {
        $id = (string) $this->nextId++;
        $this->stores[$instance][$id] = ['id' => $id, 'title' => $title, 'version' => ['number' => 1], 'parentId' => $parentId];

        return $this->stores[$instance][$id] + ['_links' => ['webui' => '/p/' . $id]];
    }

    private function update(string $instance, string $id, int $version, ?string $parentId = null): mixed
    {
        if ($version !== $this->stores[$instance][$id]['version']['number'] + 1) {
            return FakeAtlassianServer::json(['message' => 'version'], 409);
        }
        $this->stores[$instance][$id]['version']['number'] = $version;
        if (null !== $parentId) {
            $this->stores[$instance][$id]['parentId'] = $parentId;
        }

        return $this->stores[$instance][$id] + ['_links' => ['webui' => '/p/' . $id]];
    }

    /** @return array<string, mixed> */
    private function pageByTitle(string $instance, string $titleFragment): array
    {
        foreach ($this->stores[$instance] as $page) {
            if (str_contains($page['title'], $titleFragment)) {
                return $page;
            }
        }
        self::fail('Page introuvable : ' . $titleFragment);
    }

    private function pageCount(): int
    {
        return \count($this->stores['cloud']) + \count($this->stores['server']);
    }

    /** @return list<string> */
    private function allTargets(): array
    {
        $keys = [];
        foreach (PublicationFormat::cases() as $f) {
            foreach (PublicationTarget::cases() as $t) {
                $keys[] = $f->value . ':' . $t->value;
            }
        }

        return $keys;
    }

    public function testPublishesSixTargetsUnderOneContainerPerInstanceWithoutDuplicates(): void
    {
        $release = $this->prepare();
        /** @var PublicationService $service */
        $service = $this->service(PublicationService::class);

        $outcomes = $service->publish($release, $this->allTargets());
        self::assertCount(6, $outcomes);
        self::assertSame(array_fill(0, 6, PublicationOutcome::SUCCESS), array_map(static fn ($o) => $o->status, $outcomes));
        self::assertSame(8, $this->pageCount(), '2 pages de regroupement + 6 pages de format');
        self::assertSame(ReleaseStatus::Published, $release->getStatus());

        foreach (['cloud' => null, 'server' => '555'] as $instance => $projectParent) {
            $container = $this->pageByTitle($instance, '— Release notes');
            self::assertSame('CCS 2.4.0 — Release notes', $container['title']);
            self::assertSame($projectParent, $container['parentId'], 'Page de regroupement sous la page parente du projet');
            foreach (['Release note IT', 'Release note fonctionnelle', 'Nouveautés'] as $title) {
                self::assertSame($container['id'], $this->pageByTitle($instance, $title)['parentId'], $title . ' rangée sous la page de regroupement (' . $instance . ')');
            }
            self::assertSame(1, $container['version']['number'], 'Créée une seule fois malgré 3 formats');
        }
        self::assertCount(2, $release->getContainers());

        $service->publish($release, $this->allTargets());
        self::assertSame(8, $this->pageCount(), 'Republication : aucune nouvelle page');
        foreach (['cloud', 'server'] as $instance) {
            self::assertSame(1, $this->pageByTitle($instance, '— Release notes')['version']['number'], 'Regroupement inchangé : pas de nouvelle version');
            self::assertSame(2, $this->pageByTitle($instance, 'Release note IT')['version']['number']);
        }
        self::assertSame(6, $this->em->getRepository(Publication::class)->count([]));
        self::assertSame(12, $this->em->getRepository(PublicationAttempt::class)->count([]));
    }

    public function testPagesPublishedBeforeContainerAreMovedUnderIt(): void
    {
        $release = $this->prepare();
        // Page IT publiée par une version précédente de l'application, directement sous la page parente.
        $this->stores['server']['4000'] = ['id' => '4000', 'title' => 'CCS 2.4.0 — Release note IT', 'version' => ['number' => 3], 'parentId' => '555'];

        $outcomes = $this->service(PublicationService::class)->publish($release, ['it:server']);

        self::assertTrue($outcomes[0]->isSuccess());
        $container = $this->pageByTitle('server', '— Release notes');
        self::assertSame($container['id'], $this->stores['server']['4000']['parentId'], 'Page existante déplacée sous le regroupement');
        self::assertSame(4, $this->stores['server']['4000']['version']['number']);
        self::assertSame(2, \count($this->stores['server']), 'Aucun doublon de la page IT');
    }

    public function testFailureOnOneTargetDoesNotBlockOthersAndRetryWorks(): void
    {
        $release = $this->prepare();
        $this->serverDown = true;
        /** @var PublicationService $service */
        $service = $this->service(PublicationService::class);

        $outcomes = $service->publish($release, $this->allTargets());
        $byTarget = [];
        foreach ($outcomes as $o) {
            $byTarget[$o->target->value][] = $o->status;
        }
        self::assertSame(array_fill(0, 3, PublicationOutcome::SUCCESS), $byTarget['cloud']);
        self::assertSame(array_fill(0, 3, PublicationOutcome::FAILED), $byTarget['server']);
        self::assertSame(ReleaseStatus::PartiallyPublished, $release->getStatus());

        $failed = $release->findPublication(PublicationFormat::Functional, PublicationTarget::Server);
        self::assertSame(PublicationStatus::Failed, $failed->getStatus());
        self::assertStringContainsString('indisponible (503)', (string) $failed->getErrorMessage());
        self::assertStringNotContainsString('secret-', (string) $failed->getErrorMessage());

        $this->serverDown = false;
        $outcome = $service->retry($failed);
        self::assertTrue($outcome->isSuccess());
        self::assertSame(PublicationStatus::Success, $failed->getStatus());
        self::assertSame(2, $failed->getAttemptCount());
        self::assertSame(ReleaseStatus::PartiallyPublished, $release->getStatus());

        foreach ($release->getPublications() as $publication) {
            if (PublicationStatus::Failed === $publication->getStatus()) {
                $service->retry($publication);
            }
        }
        self::assertSame(ReleaseStatus::Published, $release->getStatus());
        self::assertSame(8, $this->pageCount());
    }

    public function testUnconfiguredTargetsAreSkipped(): void
    {
        $release = $this->prepare();
        $release->getProject()->setConfluenceServerConnection(null)->setConfluenceServerSpaceKey(null);
        $this->em->flush();

        $outcomes = $this->service(PublicationService::class)->publish($release, $this->allTargets());
        $skipped = array_filter($outcomes, static fn ($o) => PublicationOutcome::SKIPPED === $o->status);
        self::assertCount(3, $skipped);
        self::assertSame(ReleaseStatus::Published, $release->getStatus(), 'Toutes les cibles configurées sont publiées');
    }
}
