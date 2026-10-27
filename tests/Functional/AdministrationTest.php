<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ExternalConnection;
use App\Entity\LabelMapping;
use App\Entity\Project;
use App\Enum\ConnectionTestStatus;
use App\Service\Security\SecretEncryptionService;
use App\Tests\Support\FakeAtlassianServer;
use App\Tests\Support\WebTestCaseBase;

final class AdministrationTest extends WebTestCaseBase
{
    private function createConnection(array $values): void
    {
        $crawler = $this->client->request('GET', '/connections/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form($values));
    }

    public function testConnectionSecretIsEncryptedAndNeverSentBack(): void
    {
        $this->login();
        $this->createConnection([
            'connection[name]' => 'Jira Cloud CCS',
            'connection[provider]' => 'jira',
            'connection[deploymentType]' => 'cloud',
            'connection[baseUrl]' => 'https://acme.atlassian.net/',
            'connection[authenticationType]' => 'basic',
            'connection[username]' => 'robot@acme.com',
            'connection[plainSecret]' => 'ATATT-super-secret',
        ]);
        self::assertResponseRedirects();

        $connection = $this->em->getRepository(ExternalConnection::class)->findOneBy(['name' => 'Jira Cloud CCS']);
        self::assertNotNull($connection);
        self::assertSame('https://acme.atlassian.net', $connection->getBaseUrl());
        self::assertStringNotContainsString('super-secret', (string) $connection->getEncryptedSecret());
        self::assertSame('ATATT-super-secret', static::getContainer()->get(SecretEncryptionService::class)->decrypt((string) $connection->getEncryptedSecret()));

        $this->client->request('GET', '/connections/' . $connection->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('super-secret', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $connection->getEncryptedSecret(), (string) $this->client->getResponse()->getContent());

        // Modification sans nouveau secret : l'ancien est conservé.
        $before = $connection->getEncryptedSecret();
        $this->client->submit($this->client->getCrawler()->selectButton('Enregistrer')->form(['connection[name]' => 'Jira Cloud (renommée)']));
        $this->em->clear();
        self::assertSame($before, $this->em->find(ExternalConnection::class, $connection->getId())->getEncryptedSecret());
    }

    public function testConnectionValidationErrors(): void
    {
        $this->login();
        $this->createConnection([
            'connection[name]' => '',
            'connection[provider]' => 'jira',
            'connection[deploymentType]' => 'cloud',
            'connection[baseUrl]' => 'http://acme.atlassian.net',
            'connection[authenticationType]' => 'bearer',
            'connection[plainSecret]' => '',
        ]);
        self::assertResponseIsUnprocessable();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Le nom est obligatoire', $content);
        self::assertStringContainsString('Atlassian Cloud requiert', $content);
        self::assertStringContainsString('uniquement en HTTPS', $content);
        self::assertStringContainsString('Le token est obligatoire', $content);
        self::assertSame(0, $this->em->getRepository(ExternalConnection::class)->count([]));
    }

    public function testConnectionTestButtonRecordsResult(): void
    {
        $this->login();
        $this->createConnection([
            'connection[name]' => 'Jira DC',
            'connection[provider]' => 'jira',
            'connection[deploymentType]' => 'server',
            'connection[baseUrl]' => 'https://jira.local',
            'connection[authenticationType]' => 'bearer',
            'connection[plainSecret]' => 'PAT-123',
        ]);
        $this->server
            ->on('GET', '/rest/api/2/myself', static fn () => ['name' => 'robot', 'displayName' => 'Robot'])
            ->on('GET', '/rest/api/2/serverInfo', static fn () => ['version' => '9.12.0', 'deploymentType' => 'Server'])
            ->on('GET', '/rest/api/2/field', static fn () => [['id' => 'customfield_10100', 'schema' => ['custom' => 'com.pyxis.greenhopper.jira:gh-epic-link']]]);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Robot');

        $this->em->clear();
        $connection = $this->em->getRepository(ExternalConnection::class)->findOneBy(['name' => 'Jira DC']);
        self::assertSame(ConnectionTestStatus::Success, $connection->getLastTestStatus());
        self::assertSame('customfield_10100', $connection->getJiraEpicLinkField(), 'Champ Epic Link détecté');

        // Échec d'authentification : message explicite, pas de secret.
        $this->server->on('GET', '/rest/api/2/myself', static fn () => FakeAtlassianServer::json(['message' => 'Unauthorized'], 401));
        $crawler = $this->client->request('GET', '/connections/' . $connection->getId());
        $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Authentification refusée');
        self::assertStringNotContainsString('PAT-123', (string) $this->client->getResponse()->getContent());
    }

    public function testProjectFormValidatesConnectionTypes(): void
    {
        $this->login();
        $this->createConnection([
            'connection[name]' => 'Jira',
            'connection[provider]' => 'jira',
            'connection[deploymentType]' => 'cloud',
            'connection[baseUrl]' => 'https://acme.atlassian.net',
            'connection[authenticationType]' => 'basic',
            'connection[username]' => 'a@b.c',
            'connection[plainSecret]' => 'x',
        ]);
        $jiraId = $this->em->getRepository(ExternalConnection::class)->findOneBy(['name' => 'Jira'])->getId();

        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'project[name]' => 'CCS',
            'project[code]' => 'ccs',
            'project[jiraConnection]' => (string) $jiraId,
            'project[jiraProjectKey]' => 'ccs',
            'project[confluenceCloudSpaceKey]' => 'CCS',
        ]));
        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('Sélectionnez la connexion Confluence', (string) $this->client->getResponse()->getContent());

        $this->client->submit($this->client->getCrawler()->selectButton('Enregistrer')->form(['project[confluenceCloudSpaceKey]' => '']));
        self::assertResponseRedirects();
        $project = $this->em->getRepository(Project::class)->findOneBy(['code' => 'CCS']);
        self::assertSame('CCS', $project->getJiraProjectKey(), 'Clés normalisées en majuscules');
    }

    public function testLabelConversionTableCrud(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/settings/labels/new');
        self::assertResponseIsSuccessful();
        // Phrase obligatoire si l'étiquette n'est pas ignorée.
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['label_mapping[label]' => 'gestion-client', 'label_mapping[title]' => '']));
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($this->client->getCrawler()->selectButton('Enregistrer')->form([
            'label_mapping[label]' => 'gestion-client',
            'label_mapping[title]' => 'Évolutions de la gestion des clients',
            'label_mapping[position]' => '10',
        ]));
        self::assertResponseRedirects('/settings/labels');
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Évolutions de la gestion des clients');

        $crawler = $this->client->request('GET', '/settings/labels/new');
        $form = $crawler->selectButton('Enregistrer')->form(['label_mapping[label]' => 'release']);
        $form['label_mapping[ignored]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/labels');
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Ignorée');

        // Doublon refusé, étiquette avec espace refusée.
        $crawler = $this->client->request('GET', '/settings/labels/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['label_mapping[label]' => 'release', 'label_mapping[title]' => 'x']));
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($this->client->getCrawler()->selectButton('Enregistrer')->form(['label_mapping[label]' => 'deux mots', 'label_mapping[title]' => 'x']));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(2, $this->em->getRepository(LabelMapping::class)->count([]));
        self::assertTrue($this->em->getRepository(LabelMapping::class)->findOneBy(['label' => 'release'])->isIgnored());
    }
}
