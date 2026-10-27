<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\JiraTicket;
use App\Entity\LabelMapping;
use App\Entity\Release;
use App\Enum\ReleaseStatus;
use App\Enum\SectionSourceType;
use App\Service\ReleaseNote\ReleaseAlreadyExistsException;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\JiraFixtures;

final class ReleaseImportServiceTest extends DatabaseTestCase
{
    /** @return list<array<string, mixed>> */
    private function issues(): array
    {
        return [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche avancée', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer', ['parent' => JiraFixtures::parent('CCS-1', 'Recherche avancée')]),
            JiraFixtures::issue('CCS-3', 'Story', 'Exporter', ['parent' => JiraFixtures::parent('CCS-1', 'Recherche avancée')]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée'),
            JiraFixtures::issue('CCS-5', 'Task', 'Dépendances'),
            // Autre version : ne doit jamais être importé.
            JiraFixtures::issue('CCS-6', 'Bug', 'Bug de la 2.5', [], '10043'),
        ];
    }

    public function testImportCreatesDraftWithTicketsOfSelectedVersionOnly(): void
    {
        $project = $this->createProject();
        $issues = $this->issues();
        $this->fakeJiraCloud($issues);

        /** @var ReleaseImportService $service */
        $service = $this->service(ReleaseImportService::class);
        $release = $service->import($project, '10042');
        $this->em->clear();
        $release = $this->em->find(Release::class, $release->getId());

        self::assertSame(ReleaseStatus::Draft, $release->getStatus());
        self::assertSame('2.4.0', $release->getJiraVersionName());
        self::assertSame('2026-10-20', $release->getReleaseDate()?->format('Y-m-d'));
        $keys = array_map(static fn (JiraTicket $t) => $t->getJiraKey(), $release->getTickets()->toArray());
        sort($keys);
        self::assertSame(['CCS-1', 'CCS-2', 'CCS-3', 'CCS-4', 'CCS-5'], $keys);

        // Pagination exercée (5 tickets → 3 pages de 2).
        self::assertCount(3, $this->server->requestsMatching('POST', '/rest/api/3/search/jql'));

        $sections = $release->getSections()->toArray();
        self::assertSame(['Recherche avancée', 'Évolutions', 'Corrections', 'Tâches techniques'] === array_map(static fn ($s) => $s->getTitle(), $sections), false, 'Pas de section Évolutions vide');
        self::assertSame(['Recherche avancée', 'Corrections', 'Tâches techniques'], array_map(static fn ($s) => $s->getTitle(), $sections));
        self::assertSame(SectionSourceType::Epic, $sections[0]->getSourceType());
        self::assertSame('CCS-1', $sections[0]->getSourceKey());
        self::assertSame(['CCS-1', 'CCS-2', 'CCS-3'], array_map(static fn ($t) => $t->getJiraKey(), $sections[0]->getTickets()->toArray()));
        self::assertSame('Recherche avancée', $release->findTicketByKey('CCS-2')?->getEpicName());
        // Section technique masquée par défaut en fonctionnel / communication.
        self::assertFalse($sections[2]->isVisibleFunctional());
        self::assertTrue($sections[2]->isVisibleIt());
    }

    public function testSecondImportOfSameVersionIsRefused(): void
    {
        $project = $this->createProject();
        $issues = $this->issues();
        $this->fakeJiraCloud($issues);
        /** @var ReleaseImportService $service */
        $service = $this->service(ReleaseImportService::class);
        $first = $service->import($project, '10042');

        try {
            $service->import($project, '10042');
            self::fail('Doublon attendu');
        } catch (ReleaseAlreadyExistsException $e) {
            self::assertSame($first->getId(), $e->existing->getId());
        }
        self::assertSame(1, $this->em->getRepository(Release::class)->count([]));
        self::assertSame(5, $this->em->getRepository(JiraTicket::class)->count([]));
    }

    public function testUnknownVersionIsReported(): void
    {
        $project = $this->createProject();
        $issues = [];
        $this->fakeJiraCloud($issues);

        $this->expectExceptionMessage('n\'existe pas');
        $this->service(ReleaseImportService::class)->import($project, '99999');
    }

    public function testTicketsWithoutEpicAreGroupedByConvertedLabelAndRefreshReusesTheSection(): void
    {
        $project = $this->createProject();
        $this->em->persist((new LabelMapping())->setLabel('release')->setIgnored(true));
        $this->em->persist((new LabelMapping())->setLabel('gestion-client')->setTitle('Évolutions de la gestion des clients')->setProject($project));
        $this->em->flush();
        $issues = [
            JiraFixtures::issue('CCS-4', 'Bug', 'Fiche client', ['labels' => ['release', 'gestion-client']]),
            JiraFixtures::issue('CCS-5', 'Task', 'Purge', ['labels' => ['release']]),
        ];
        $this->fakeJiraCloud($issues);
        $release = $this->service(ReleaseImportService::class)->import($project, '10042');

        $sections = $release->getSections()->toArray();
        self::assertSame(['Évolutions de la gestion des clients', 'Tâches techniques'], array_map(static fn ($s) => $s->getTitle(), $sections));
        self::assertSame([SectionSourceType::Label, 'gestion-client'], [$sections[0]->getSourceType(), $sections[0]->getSourceKey()]);

        // Un nouveau ticket portant la même étiquette rejoint la section existante (titre éventuellement retouché).
        $sections[0]->setTitle('Gestion des clients');
        $this->em->flush();
        $issues[] = JiraFixtures::issue('CCS-6', 'Story', 'Recherche client', ['labels' => ['Gestion-Client']]);
        $report = $this->service(\App\Service\ReleaseNote\ReleaseRefreshService::class)->apply($release, true, false);
        self::assertSame(0, $report['sections_created']);
        self::assertSame($sections[0]->getId(), $release->findTicketByKey('CCS-6')?->getSection()?->getId());
    }
}
