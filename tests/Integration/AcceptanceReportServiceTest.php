<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcceptanceReport;
use App\Entity\Release;
use App\Enum\AcceptanceDecision;
use App\Enum\AcceptanceDocumentStatus;
use App\Enum\PublicationFormat;
use App\Enum\ReleaseStatus;
use App\Service\AcceptanceReport\AcceptanceReportService;
use App\Service\Publication\PublicationService;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Service\ReleaseNote\ReleaseNoteRenderer;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeAtlassianServer;
use App\Tests\Support\JiraFixtures;

final class AcceptanceReportServiceTest extends DatabaseTestCase
{
    /** @var list<array<string, mixed>> */
    private array $issues = [];

    private function adf(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function prepare(): Release
    {
        $project = $this->createProject(false);
        $this->issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Gestion clients', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Créer un client', ['parent' => JiraFixtures::parent('CCS-1', 'Gestion clients')]),
            JiraFixtures::issue('CCS-3', 'Story', 'Modifier un client'),
            JiraFixtures::issue('CCS-4', 'Bug', 'Adresse tronquée', ['status' => ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate']]]),
            JiraFixtures::issue('CCS-5', 'Bug', 'Date de naissance', ['resolution' => ['name' => 'Corrigé']]),
            JiraFixtures::issue('CCS-6', 'Task', 'Purge des logs'),
        ];
        $this->fakeJiraCloud($this->issues);
        $comments = [
            'CCS-2' => [
                ['id' => '1', 'author' => ['displayName' => 'Awa N.'], 'created' => '2026-09-10T10:00:00.000+0200', 'body' => $this->adf('TEST : création OK sur REC02')],
                ['id' => '2', 'author' => ['displayName' => 'Dev'], 'created' => '2026-09-09T09:00:00.000+0200', 'body' => $this->adf('Commentaire de dev non repris')],
                ['id' => '3', 'author' => ['displayName' => 'Rossi A.'], 'created' => '2026-09-11T15:30:00.000+0200', 'body' => $this->adf('test: non-régression <OK>')],
            ],
            'CCS-3' => [],
            'CCS-6' => [['id' => '4', 'author' => ['displayName' => 'Ops'], 'created' => '2026-09-12T08:00:00.000+0200', 'body' => $this->adf('TEST : purge vérifiée')]],
        ];
        $this->server->on('GET', '/rest/api/3/issue/[A-Z]+-\d+/comment', static function (array $b, string $url) use ($comments) {
            preg_match('#/issue/([A-Z]+-\d+)/comment#', $url, $m);
            $list = $comments[$m[1]] ?? [];

            return ['startAt' => 0, 'maxResults' => 100, 'total' => \count($list), 'comments' => $list];
        });

        return $this->service(ReleaseImportService::class)->import($project, '10042');
    }

    public function testCreateFillsDefaultsAndJiraSnapshot(): void
    {
        $release = $this->prepare();
        /** @var AcceptanceReportService $service */
        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $counts = $service->refreshFromJira($report);

        self::assertSame('CCS - v 2.4.0', $report->getVersionLabel());
        self::assertSame('2026-10-20', $report->getMepDate()?->format('Y-m-d'));
        self::assertSame(AcceptanceDocumentStatus::Draft, $report->getDocumentStatus());
        self::assertSame(['scope' => 3, 'tests' => 2, 'anomalies' => 2], $counts);

        self::assertSame(['CCS-2', 'CCS-3', 'CCS-6'], array_column($report->getScopeItems(), 'key'), 'US hors anomalies et hors Epic');
        self::assertSame(['CCS-4', 'CCS-5'], array_column($report->getAnomalyItems(), 'key'));
        self::assertSame('Corrigé', $report->getAnomalyItems()[1]['resolution']);

        $tests = $report->getTestItems();
        self::assertSame(['CCS-2', 'CCS-6'], array_column($tests, 'key'), 'Regroupement par US, US sans test absente');
        self::assertSame(['création OK sur REC02', 'non-régression <OK>'], array_column($tests[0]['comments'], 'text'), 'Préfixe retiré, ordre chronologique, commentaire hors test exclu');
        self::assertSame('Awa N.', $tests[0]['comments'][0]['author']);
    }

    public function testNextReportIsPrefilledFromPreviousOneOfTheProject(): void
    {
        $release = $this->prepare();
        $service = $this->service(AcceptanceReportService::class);
        $first = $service->create($release);
        $first->setDocumentTitle('Digital Courtage - Finagora - Gestion Clients')->setCode('PAME')
            ->setAcceptanceManagers("Jonathan GONDELLIER\nRossi AMPION")
            ->setPhases([['phase' => 'Tests système', 'environment' => 'Socle Courtage REC02', 'actualStart' => '2026-09-09', 'actualEnd' => '2026-09-14']])
            ->setTestCommentPrefix('RECETTE :')->setDecision(AcceptanceDecision::Accepted);
        $this->em->flush();

        $release2 = $this->service(ReleaseImportService::class)->import($release->getProject(), '10043');
        $second = $service->create($release2);

        self::assertSame('Digital Courtage - Finagora - Gestion Clients', $second->getDocumentTitle());
        self::assertSame('PAME', $second->getCode());
        self::assertSame(['Jonathan GONDELLIER', 'Rossi AMPION'], $second->getAcceptanceManagerList());
        self::assertSame('RECETTE :', $second->getTestCommentPrefix());
        self::assertSame('Socle Courtage REC02', $second->getPhases()[0]['environment']);
        self::assertNull($second->getPhases()[0]['actualStart'], 'Dates non reprises');
        self::assertNull($second->getDecision(), 'Décision non reprise');
        self::assertSame('CCS - v 2.5.0', $second->getVersionLabel());
    }

    public function testRenderingAndPublicationUnderVersionContainer(): void
    {
        $release = $this->prepare();
        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);
        $report->setDocumentTitle('Digital Courtage - Finagora - Gestion Clients')
            ->setDocumentStatus(AcceptanceDocumentStatus::Final)
            ->setDecision(AcceptanceDecision::AcceptedWithReserves)->setReserves('Libellé à revoir')
            ->setPhases([['phase' => 'Tests système', 'actualStart' => '2026-09-09', 'actualEnd' => '2026-09-14', 'environment' => 'REC02']])
            ->setAcceptanceSignatory('Jonathan GONDELLIER')->setAcceptanceSignedAt(new \DateTimeImmutable('2026-09-15'));
        $this->em->flush();

        /** @var ReleaseNoteRenderer $renderer */
        $renderer = $this->service(ReleaseNoteRenderer::class);
        $web = $renderer->renderWeb($release, PublicationFormat::AcceptanceReport);
        self::assertStringContainsString('Du 09/09/2026<br>au 14/09/2026', $web);
        self::assertStringContainsString('non-régression &lt;OK&gt;', $web, 'Commentaires Jira échappés');
        self::assertStringContainsString('2 anomalies détectées</strong>, dont 1 terminée', $web);

        $storage = $renderer->renderStorage($release, PublicationFormat::AcceptanceReport);
        $renderer->assertWellFormed($storage);
        self::assertStringContainsString('DÉFINITIF', $storage);
        self::assertStringContainsString('<ac:emoticon ac:name="tick" /> <strong>Acceptée avec réserves</strong>', $storage);
        self::assertStringContainsString('Jonathan GONDELLIER', $storage);
        self::assertStringNotContainsString('&lt;p&gt;', $storage, 'Pas de HTML ré-échappé dans les macros');
        self::assertSame('CCS 2.4.0 — PV de recette', $renderer->pageTitle($release, PublicationFormat::AcceptanceReport));

        // Publication : la page du PV est rangée sous la page de regroupement de la version.
        $created = [];
        $this->server
            ->on('GET', '/wiki/api/v2/spaces', static fn () => ['results' => [['id' => '777', 'key' => 'CCS']]])
            ->on('GET', '/wiki/api/v2/pages', static fn () => ['results' => []])
            ->on('POST', '/wiki/api/v2/pages', static function (array $b) use (&$created) {
                $id = (string) (9000 + \count($created));
                $created[$id] = ['title' => $b['title'], 'parentId' => $b['parentId'] ?? null];

                return ['id' => $id, 'title' => $b['title'], 'version' => ['number' => 1], '_links' => ['webui' => '/p/' . $id]];
            });
        /** @var PublicationService $publication */
        $publication = $this->service(PublicationService::class);
        $keys = array_map(static fn ($r) => $r->key(), $publication->getTargetMatrix($release));
        self::assertContains('acceptance:cloud', $keys);

        $outcomes = $publication->publish($release, ['acceptance:cloud']);
        self::assertTrue($outcomes[0]->isSuccess(), $outcomes[0]->message);
        $pages = array_column($created, 'parentId', 'title');
        self::assertSame(array_search('CCS 2.4.0 — Release notes', array_column($created, 'title', null), true) + 9000, (int) $pages['CCS 2.4.0 — PV de recette']);
        self::assertSame(ReleaseStatus::Draft, $release->getStatus(), 'Le PV seul ne change pas le statut des release notes');
    }

    public function testFinalReportRequiresDecisionAndReservesWhenNeeded(): void
    {
        $validator = self::getContainer()->get('validator');
        $report = (new AcceptanceReport())->setDocumentTitle('T')->setApplicationName('A')->setVersionLabel('V')
            ->setDocumentStatus(AcceptanceDocumentStatus::Final);
        self::assertStringContainsString('décision', (string) $validator->validate($report));
        $report->setDecision(AcceptanceDecision::AcceptedWithReserves);
        self::assertStringContainsString('réserves', (string) $validator->validate($report));
        $report->setReserves('Une réserve');
        self::assertCount(0, $validator->validate($report));
    }

    public function testJiraErrorIsRecordedWithoutLosingContent(): void
    {
        $release = $this->prepare();
        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $report->setComments('Texte saisi');
        $this->server->on('GET', '/rest/api/3/project/CCS/versions', static fn () => FakeAtlassianServer::json([], 503));

        try {
            $service->refreshFromJira($report);
            self::fail('Exception attendue');
        } catch (\App\Service\Http\ExternalApiException) {
        }
        self::assertStringContainsString('indisponible', (string) $report->getJiraSyncError());
        self::assertSame('Texte saisi', $report->getComments());
    }
}
