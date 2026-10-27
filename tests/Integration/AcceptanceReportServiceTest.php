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
            JiraFixtures::issue('CCS-3', 'Story', 'Modifier un client', ['issuelinks' => [
                ['type' => ['name' => 'Blocks', 'inward' => 'est bloqué par', 'outward' => 'bloque'], 'inwardIssue' => ['key' => 'CCS-9', 'fields' => ['summary' => 'Doublon de client', 'issuetype' => ['name' => 'Bug'], 'status' => ['name' => 'À faire', 'statusCategory' => ['key' => 'new']], 'priority' => ['name' => 'High']]]],
                ['type' => ['name' => 'Relates', 'inward' => 'est lié à', 'outward' => 'est lié à'], 'outwardIssue' => ['key' => 'CCS-4', 'fields' => ['summary' => 'Adresse tronquée', 'issuetype' => ['name' => 'Bug'], 'status' => ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate']]]]],
                ['type' => ['name' => 'Relates', 'inward' => 'est lié à', 'outward' => 'est lié à'], 'outwardIssue' => ['key' => 'CCS-6', 'fields' => ['summary' => 'Purge', 'issuetype' => ['name' => 'Task'], 'status' => ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']]]]],
            ]]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Adresse tronquée', ['status' => ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate']]]),
            JiraFixtures::issue('CCS-5', 'Bug', 'Date de naissance', ['resolution' => ['name' => 'Corrigé']]),
            JiraFixtures::issue('CCS-6', 'Task', 'Purge des logs'),
            // Anomalie hors version, liée à CCS-3 : relue par clé.
            JiraFixtures::issue('CCS-9', 'Bug', 'Doublon de client', ['status' => ['name' => 'À faire', 'statusCategory' => ['key' => 'new']], 'priority' => ['name' => 'High']], '10043'),
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
        self::assertSame(['scope' => 3, 'tests' => 2, 'anomalies' => 3], $counts);

        self::assertSame(['CCS-2', 'CCS-3', 'CCS-6'], array_column($report->getScopeItems(), 'key'), 'US hors anomalies et hors Epic');
        $anomalies = $report->getAnomalyItems();
        self::assertSame(['CCS-4', 'CCS-9', 'CCS-5'], array_column($anomalies, 'key'), 'Non terminées d\'abord, anomalie liée incluse');
        self::assertSame(['version', 'linked', 'version'], array_column($anomalies, 'origin'));
        self::assertSame(['CCS-3 (est bloqué par)'], $anomalies[1]['linkedFrom']);
        self::assertSame(['CCS-3 (est lié à)'], $anomalies[0]['linkedFrom'], 'Anomalie de version également liée : pas de doublon');
        self::assertSame('High', $anomalies[1]['priority'], 'Données complètes relues dans Jira');
        self::assertSame('Corrigé', $anomalies[2]['resolution']);

        // Réserves par défaut = anomalies non terminées.
        self::assertSame("Anomalies non terminées à la date du PV :\n- CCS-4 — Adresse tronquée (En cours)\n- CCS-9 — Doublon de client (À faire)", $report->getReserves());

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
        self::assertStringContainsString('3 anomalies détectées</strong> (2 de la version, 1 liée aux fiches du périmètre)', $web);
        self::assertStringContainsString('Anomalies non terminées (2)', $web);
        self::assertStringContainsString('Anomalies terminées (1)', $web);
        self::assertStringContainsString('Liée à CCS-3 (est bloqué par)', $web);

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

    public function testDefaultReservesFollowJiraUntilEditedByUser(): void
    {
        $release = $this->prepare();
        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);
        self::assertStringContainsString('CCS-9', (string) $report->getReserves());

        // CCS-9 terminée dans Jira : réserves générées mises à jour automatiquement.
        foreach ($this->issues as &$issue) {
            if ('CCS-9' === $issue['key']) {
                $issue['fields']['status'] = ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']];
            }
        }
        unset($issue);
        $service->refreshFromJira($report);
        self::assertStringNotContainsString('CCS-9', (string) $report->getReserves());
        self::assertStringContainsString('CCS-4', (string) $report->getReserves());

        // Texte modifié par l'utilisateur : jamais remplacé.
        $report->setReserves('Réserve rédigée par le PO');
        $service->refreshFromJira($report);
        self::assertSame('Réserve rédigée par le PO', $report->getReserves());
    }

    public function testDedicatedParentPagePublishesReportOutsideVersionContainer(): void
    {
        $release = $this->prepare();
        $release->getProject()->setConfluenceCloudAcceptanceParentPageId('321');
        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);

        $created = [];
        $this->server
            ->on('GET', '/wiki/api/v2/spaces', static fn () => ['results' => [['id' => '777', 'key' => 'CCS']]])
            ->on('GET', '/wiki/api/v2/pages', static fn () => ['results' => []])
            ->on('POST', '/wiki/api/v2/pages', static function (array $b) use (&$created) {
                $created[] = ['title' => $b['title'], 'parentId' => $b['parentId'] ?? null];

                return ['id' => (string) (9000 + \count($created)), 'title' => $b['title'], 'version' => ['number' => 1], '_links' => ['webui' => '/p']];
            });
        $publication = $this->service(PublicationService::class);
        $outcomes = $publication->publish($release, ['acceptance:cloud']);

        self::assertTrue($outcomes[0]->isSuccess(), $outcomes[0]->message);
        self::assertSame([['title' => 'CCS 2.4.0 — PV de recette', 'parentId' => '321']], $created, 'Pas de page de regroupement, parent dédié');

        $publication->publish($release, ['it:cloud']);
        self::assertSame('CCS 2.4.0 — Release notes', $created[1]['title'], 'Les release notes gardent leur page de regroupement');
    }

    public function testOctaneCoverageIsReadThroughTheJiraPlugin(): void
    {
        $release = $this->prepare();
        $release->getProject()->setOctaneCoverageEnabled(true);
        $this->em->flush();
        $coverage = [
            'CCS-2' => ['issueKey' => 'CCS-2', 'status' => 'hasData', 'octaneEntity' => ['fields' => ['id' => '4012', 'name' => 'Créer un client', 'testTabUrl' => 'https://octane.local/tests/4012']], 'totalTestsCount' => 5, 'totalExecutedTestsCount' => 4,
                'coverageGroups' => [['fields' => ['id' => 'run_status_passed', 'name' => 'Passed', 'count' => 3, 'order' => 1]], ['fields' => ['id' => 'run_status_failed', 'name' => 'Failed', 'count' => 1, 'order' => 2]]]],
            'CCS-3' => ['issueKey' => 'CCS-3', 'status' => 'noData'],
            'CCS-6' => ['issueKey' => 'CCS-6', 'status' => 'hasData', 'octaneEntity' => ['fields' => ['id' => '4013']], 'totalTestsCount' => 1, 'totalExecutedTestsCount' => 1,
                'coverageGroups' => [['fields' => ['id' => 'run_status_passed', 'name' => 'Passed', 'count' => 1, 'order' => 1]]]],
        ];
        $this->server
            ->on('GET', '/rest/octane-coverage/1.0/coverage/octane-workspaces', static fn () => [['id' => '15344388-6de8-4969-b5a9-3ee35b9ae8bb', 'spaceConfigId' => 'x', 'octaneWorkspaces' => [['id' => '168001', 'name' => 'Courtage']]]])
            ->on('GET', '/rest/octane-coverage/1.0/coverage', static fn (array $b, string $url, array $q) => $coverage[$q['issue-key']]);

        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);

        $calls = $this->server->requestsMatching('GET', '/rest/octane-coverage/1.0/coverage?');
        self::assertCount(3, $calls);
        parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $q);
        self::assertSame(['project-key' => 'CCS', 'issue-key' => 'CCS-2', 'issue-id' => '90002', 'workspace-config-id' => '15344388-6de8-4969-b5a9-3ee35b9ae8bb', 'workspace-id' => '168001'], $q);
        self::assertCount(2, $this->server->requestsMatching('GET', 'octane-workspaces'), 'Découverte mise en cache par type de ticket (Story, Task)');

        self::assertSame(['withData' => 2, 'totalTests' => 6, 'executedTests' => 5, 'groups' => [['id' => 'passed', 'name' => 'Passed', 'count' => 4], ['id' => 'failed', 'name' => 'Failed', 'count' => 1]]], $report->getCoverageSummary());
        self::assertNull($report->getCoverageError());

        $renderer = $this->service(ReleaseNoteRenderer::class);
        $web = $renderer->renderWeb($release, PublicationFormat::AcceptanceReport);
        self::assertStringContainsString('<strong>6 tests</strong> liés à 2 fiches sur 3', $web);
        self::assertStringContainsString('Aucune entité Octane', $web);
        self::assertStringContainsString('1 sans passage', $web);
        $storage = $renderer->renderStorage($release, PublicationFormat::AcceptanceReport);
        $renderer->assertWellFormed($storage);
        self::assertStringContainsString('<ac:parameter ac:name="title">3 Passed</ac:parameter>', $storage);
        self::assertStringContainsString('<a href="https://octane.local/tests/4012">5 tests</a>', $storage);
        // Code couleur : CCS-2 (1 échec) rouge, CCS-6 (tout passé) vert, CCS-3 (pas d'entité) gris.
        self::assertStringContainsString('<td class="highlight-red" data-highlight-colour="red">', $storage);
        self::assertStringContainsString('<td class="highlight-green" data-highlight-colour="green">', $storage);
        self::assertStringContainsString('<td class="highlight-grey" data-highlight-colour="grey">', $storage);
        self::assertStringContainsString('<ac:parameter ac:name="title">En échec : 1</ac:parameter>', $storage);
        self::assertStringContainsString('<td>4</td><td>66,7 %</td>', $storage, 'Ligne Passed du résumé (4 sur 6)');
        self::assertStringContainsString('<td class="rn-health rn-health-failed">', $web);
    }

    public function testMissingOctanePluginDoesNotBlockRefresh(): void
    {
        $release = $this->prepare();
        $release->getProject()->setOctaneCoverageEnabled(true)->setOctaneWorkspaceConfigId('cfg')->setOctaneWorkspaceId('1');
        $this->em->flush();

        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $counts = $service->refreshFromJira($report);

        self::assertSame(3, $counts['scope']);
        self::assertStringContainsString('introuvable', (string) $report->getCoverageError());
        self::assertCount(1, $this->server->requestsMatching('GET', '/rest/octane-coverage/'), 'Arrêt après la première erreur 404');
        self::assertCount(0, $this->server->requestsMatching('GET', 'octane-workspaces'), 'Identifiants forcés : pas de découverte');
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

    public function testCommentsAreReadWithTheSearchInsteadOfOneCallPerTicket(): void
    {
        $release = $this->prepare();
        $this->server->requests = [];
        // Jira renvoie les commentaires dans la recherche ; CCS-3 en a trop (tronqués) → relecture unitaire.
        $this->issues[1]['fields']['comment'] = ['total' => 1, 'maxResults' => 1, 'comments' => [
            ['id' => '1', 'author' => ['displayName' => 'Awa N.'], 'created' => '2026-09-10T10:00:00.000+0200', 'body' => $this->adf('TEST : création OK')],
        ]];
        $this->issues[2]['fields']['comment'] = ['total' => 120, 'maxResults' => 1, 'comments' => [['id' => '9', 'body' => $this->adf('…')]]];
        $this->issues[5]['fields']['comment'] = ['total' => 0, 'maxResults' => 0, 'comments' => []];

        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);

        self::assertContains('comment', $this->server->requestsMatching('POST', '/rest/api/3/search/jql')[0]['json']['fields']);
        $commentCalls = $this->server->requestsMatching('GET', '/comment');
        self::assertCount(1, $commentCalls, 'Seul le ticket aux commentaires tronqués est relu');
        self::assertStringContainsString('/issue/CCS-3/comment', $commentCalls[0]['url']);
        self::assertSame(['CCS-2'], array_column($report->getTestItems(), 'key'));
        self::assertSame(['création OK'], array_column($report->getTestItems()[0]['comments'], 'text'));
    }

    public function testOctaneErrorOnOneTicketDoesNotPreventOthers(): void
    {
        $release = $this->prepare();
        $release->getProject()->setOctaneCoverageEnabled(true)->setOctaneWorkspaceConfigId('cfg')->setOctaneWorkspaceId('1');
        $this->em->flush();
        $this->server->on('GET', '/rest/octane-coverage/1.0/coverage', static fn (array $b, string $url, array $q) => 'CCS-3' === $q['issue-key']
            ? FakeAtlassianServer::json(['message' => 'boom'], 500)
            : ['issueKey' => $q['issue-key'], 'status' => 'hasData', 'octaneEntity' => ['fields' => ['id' => '1']], 'totalTestsCount' => 2, 'totalExecutedTestsCount' => 2,
                'coverageGroups' => [['fields' => ['id' => 'run_status_passed', 'name' => 'Passed', 'count' => 2]]]]);

        $service = $this->service(AcceptanceReportService::class);
        $report = $service->create($release);
        $service->refreshFromJira($report);

        self::assertCount(3, $this->server->requestsMatching('GET', '/rest/octane-coverage/1.0/coverage?'));
        self::assertSame(['CCS-2' => 2, 'CCS-3' => null, 'CCS-6' => 2], array_combine(
            array_column($report->getScopeItems(), 'key'),
            array_map(static fn ($i) => $i['coverage']['totalTests'] ?? null, $report->getScopeItems()),
        ));
        self::assertNotNull($report->getCoverageError());
    }
}
