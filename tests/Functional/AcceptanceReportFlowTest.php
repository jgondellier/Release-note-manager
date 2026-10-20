<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcceptanceReport;
use App\Entity\ExternalConnection;
use App\Entity\IssueTypeMapping;
use App\Entity\Project;
use App\Entity\Release;
use App\Enum\AcceptanceDecision;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\SectionCategory;
use App\Service\ReleaseNote\ReleaseImportService;
use App\Service\Security\SecretEncryptionService;
use App\Tests\Support\JiraFixtures;
use App\Tests\Support\WebTestCaseBase;

final class AcceptanceReportFlowTest extends WebTestCaseBase
{
    private function release(): Release
    {
        $encryption = static::getContainer()->get(SecretEncryptionService::class);
        $jira = (new ExternalConnection())->setName('Jira')->setProvider(ConnectionProvider::Jira)->setDeploymentType(DeploymentType::Cloud)
            ->setBaseUrl('https://acme.atlassian.net')->setAuthenticationType(AuthenticationType::Basic)->setUsername('a@b.c')->setEncryptedSecret($encryption->encrypt('t'));
        $wiki = (new ExternalConnection())->setName('Wiki')->setProvider(ConnectionProvider::Confluence)->setDeploymentType(DeploymentType::Cloud)
            ->setBaseUrl('https://acme.atlassian.net')->setAuthenticationType(AuthenticationType::Basic)->setUsername('a@b.c')->setEncryptedSecret($encryption->encrypt('t'));
        $project = (new Project())->setName('Finagora')->setCode('FIN')->setJiraConnection($jira)->setJiraProjectKey('CCS')
            ->setConfluenceCloudConnection($wiki)->setConfluenceCloudSpaceKey('FIN');
        foreach ([$jira, $wiki, $project] as $e) {
            $this->em->persist($e);
        }
        $this->em->persist((new IssueTypeMapping())->setJiraIssueType('Bug')->setSectionTitle('Corrections')->setCategory(SectionCategory::Fix));
        $this->em->flush();

        $issues = [JiraFixtures::issue('CCS-2', 'Story', 'Créer un client'), JiraFixtures::issue('CCS-4', 'Bug', 'Adresse tronquée')];
        $this->server
            ->on('GET', '/rest/api/3/project/CCS/versions', static fn () => JiraFixtures::versions())
            ->on('POST', '/rest/api/3/search/jql', static fn () => ['issues' => $issues, 'isLast' => true])
            ->on('GET', '/rest/api/3/issue/CCS-2/comment', static fn () => ['total' => 1, 'comments' => [['id' => '1', 'author' => ['displayName' => 'Awa'], 'created' => '2026-09-10T10:00:00.000+0200', 'body' => 'TEST : validé']]]);

        return static::getContainer()->get(ReleaseImportService::class)->import($project, '10042');
    }

    public function testCreateEditPreviewAndPublishScreen(): void
    {
        $release = $this->release();
        $this->login();

        $crawler = $this->client->request('GET', '/releases/' . $release->getId() . '/acceptance');
        self::assertSelectorTextContains('h2', 'Créer le procès-verbal');
        $this->client->submit($crawler->selectButton('Créer le PV de recette')->form());
        self::assertResponseRedirects('/releases/' . $release->getId() . '/acceptance');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 fiche(s) dans le périmètre, 1 avec des commentaires de tests, 1 anomalie(s)');

        $form = $crawler->selectButton('Enregistrer le PV')->form([
            'acceptance_report[documentTitle]' => 'Digital Courtage - Finagora - Gestion Clients',
            'acceptance_report[code]' => 'PAME',
            'acceptance_report[acceptanceManagers]' => "Jonathan GONDELLIER - Product Owner\nRossi AMPION - Test Lead",
            'acceptance_report[phases][0][actualStart]' => '2026-09-09',
            'acceptance_report[phases][0][actualEnd]' => '2026-09-14',
            'acceptance_report[phases][0][environment]' => 'Socle Courtage REC02',
            'acceptance_report[decision]' => 'accepted',
            'acceptance_report[documentStatus]' => 'final',
            'acceptance_report[acceptanceSignatory]' => 'Jonathan GONDELLIER',
            'acceptance_report[acceptanceSignedAt]' => '2026-09-15',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $report = $this->em->getRepository(AcceptanceReport::class)->findOneBy([]);
        self::assertSame('PAME', $report->getCode());
        self::assertSame(AcceptanceDecision::Accepted, $report->getDecision());
        self::assertSame('Socle Courtage REC02', $report->getPhases()[0]['environment']);
        self::assertSame('2026-09-09', $report->getPhases()[0]['actualStart']);

        $this->client->request('GET', '/releases/' . $release->getId() . '/acceptance/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview-frame', 'Digital Courtage - Finagora - Gestion Clients');
        self::assertSelectorTextContains('.preview-frame', 'validé');
        self::assertSelectorTextContains('.preview-frame .rn-decision .is-selected', 'Acceptée sans réserve');

        $this->client->request('GET', '/releases/' . $release->getId() . '/acceptance/preview/storage');
        self::assertSelectorTextContains('#storage-source', 'Procès-verbal de tests système');

        $this->client->request('GET', '/releases/' . $release->getId() . '/publish');
        self::assertSelectorTextContains('.target-grid', 'PV de recette');
    }
}
