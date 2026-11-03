<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ExternalConnection;
use App\Entity\IssueTypeMapping;
use App\Entity\Project;
use App\Entity\Release;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\SectionCategory;
use App\Service\Security\SecretEncryptionService;
use App\Tests\Support\FakeAtlassianServer;
use App\Tests\Support\JiraFixtures;
use App\Tests\Support\WebTestCaseBase;

/**
 * Parcours complet par l'interface : création depuis Jira, édition, déplacement, aperçus.
 */
final class ReleaseEditorTest extends WebTestCaseBase
{
    private function project(): Project
    {
        $connection = (new ExternalConnection())->setName('Jira')->setProvider(ConnectionProvider::Jira)->setDeploymentType(DeploymentType::Cloud)
            ->setBaseUrl('https://acme.atlassian.net')->setAuthenticationType(AuthenticationType::Basic)->setUsername('a@b.c')
            ->setEncryptedSecret(static::getContainer()->get(SecretEncryptionService::class)->encrypt('tok'));
        $project = (new Project())->setName('CCS')->setCode('CCS')->setJiraConnection($connection)->setJiraProjectKey('CCS');
        $this->em->persist($connection);
        $this->em->persist($project);
        $this->em->persist((new IssueTypeMapping())->setJiraIssueType('Bug')->setSectionTitle('Corrections')->setCategory(SectionCategory::Fix));
        $this->em->flush();

        $issues = [
            JiraFixtures::issue('CCS-1', 'Epic', 'Recherche', [], '10042', true),
            JiraFixtures::issue('CCS-2', 'Story', 'Filtrer', ['parent' => JiraFixtures::parent('CCS-1', 'Recherche')]),
            JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée'),
        ];
        $this->server
            ->on('GET', '/rest/api/3/project/CCS/versions', static fn () => JiraFixtures::versions())
            ->on('POST', '/rest/api/3/search/jql', static fn () => ['issues' => $issues, 'isLast' => true]);

        return $project;
    }

    public function testFullEditorialFlow(): void
    {
        $project = $this->project();
        $this->login();

        // 1-2. Choix du projet et des versions.
        $crawler = $this->client->request('GET', '/releases/new/' . $project->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.version-list', '2.4.0');

        // 3. Aperçu puis import.
        $crawler = $this->client->click($crawler->selectLink('Sélectionner')->link());
        self::assertSelectorTextContains('body', 'Regroupement proposé');
        $searchesAfterPreview = \count($this->server->requestsMatching('POST', '/rest/api/3/search/jql'));
        self::assertSelectorTextContains('body', 'fixVersion = 10042');
        $this->client->submit($crawler->selectButton('Importer et créer le brouillon')->form(['title' => 'Ma release 2.4']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ma release 2.4');
        self::assertCount($searchesAfterPreview, $this->server->requestsMatching('POST', '/rest/api/3/search/jql'), 'Import confirmé sans relire Jira');

        $release = $this->em->getRepository(Release::class)->findOneBy([]);
        $epic = $release->getSections()->first();
        $fixes = $release->getSections()->get(1);

        // Édition du wording.
        $crawler = $this->client->request('GET', '/sections/' . $epic->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'release_section[title]' => 'Recherche multicritère',
            'release_section[functionalContent]' => "Les gestionnaires filtrent **par statut**.\n- export CSV",
            'release_section[communicationContent]' => 'Gagnez du temps !',
        ]));
        self::assertResponseRedirects();

        // Déplacement du bug dans la section d'Epic.
        $ticket = $release->findTicketByKey('CCS-4');
        $crawler = $this->client->request('GET', '/releases/' . $release->getId());
        $form = $crawler->filter('#move-' . $ticket->getId())->ancestors()->filter('form')->form(['section_id' => (string) $epic->getId()]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        // Section manuelle sans ticket.
        $crawler = $this->client->request('GET', '/releases/' . $release->getId() . '/sections/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'release_section[title]' => 'Message de l\'équipe',
            'release_section[communicationContent]' => 'Merci pour vos retours.',
            'release_section[deliveryConfirmed]' => true,
        ]));
        self::assertResponseRedirects();

        // Suppression de la section « Corrections » devenue vide.
        $crawler = $this->client->request('GET', '/releases/' . $release->getId());
        $this->client->submit($crawler->filter('#section-' . $fixes->getId() . ' form[action$="/delete"]')->form());
        self::assertResponseRedirects();

        $this->em->clear();
        $release = $this->em->find(Release::class, $release->getId());
        self::assertSame(['Recherche multicritère', 'Message de l\'équipe'], array_map(static fn ($s) => $s->getTitle(), $release->getSections()->toArray()));
        self::assertSame('Recherche multicritère', $release->findTicketByKey('CCS-4')->getSection()?->getTitle());

        // Aperçus des trois formats et source Confluence.
        $this->client->request('GET', '/releases/' . $release->getId() . '/preview/functional');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview-frame', 'par statut');
        $this->client->request('GET', '/releases/' . $release->getId() . '/preview/communication');
        self::assertSelectorTextContains('.rn-cards', 'Merci pour vos retours');
        // CCS-4 (terminé) + CCS-1/CCS-2 terminés : section d'Epic livrée, présentée comme nouveauté.
        self::assertSelectorTextContains('.rn-cards', 'Gagnez du temps');
        $this->client->request('GET', '/releases/' . $release->getId() . '/preview/it/storage');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#storage-source', 'ac:structured-macro');

        // Archivage : lecture seule.
        $crawler = $this->client->request('GET', '/releases/' . $release->getId());
        $this->client->submit($crawler->filter('form[action$="/archive"]')->form());
        $this->client->request('GET', '/sections/' . $epic->getId() . '/edit');
        self::assertResponseRedirects('/releases/' . $release->getId());
    }

    public function testRefreshScreenProposesReclassificationWhenLabelChanged(): void
    {
        $project = $this->project();
        $issues = [JiraFixtures::issue('CCS-4', 'Bug', 'Date erronée', ['labels' => ['ancien-lot']])];
        $this->server->on('POST', '/rest/api/3/search/jql', static function () use (&$issues) {
            return ['issues' => $issues, 'isLast' => true];
        });
        $release = static::getContainer()->get(\App\Service\ReleaseNote\ReleaseImportService::class)->import($project, '10042');
        $issues[0]['fields']['labels'] = ['nouveau-lot'];
        $this->login();

        $crawler = $this->client->request('GET', '/releases/' . $release->getId() . '/refresh');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-alert', 'tickets à reclasser (1)');
        self::assertSelectorTextContains('.card-alert', '« ancien-lot » → « nouveau-lot »');

        $requestsAfterPreview = \count($this->server->requests);
        $this->client->submit($crawler->selectButton('Appliquer l\'actualisation')->form());
        self::assertCount($requestsAfterPreview, $this->server->requests, 'Actualisation confirmée avec les données affichées, sans relire Jira');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '1 reclassé(s)');
        $this->em->clear();
        self::assertSame('nouveau-lot', $this->em->find(Release::class, $release->getId())->findTicketByKey('CCS-4')->getSection()?->getTitle());
    }

    public function testRefreshOfLargeReleaseConfirmsWithoutCallingJiraAgain(): void
    {
        $project = $this->project();
        $issues = [];
        for ($n = 1; $n <= 130; ++$n) {
            $issues[] = JiraFixtures::issue('CCS-' . (100 + $n), 0 === $n % 2 ? 'Bug' : 'Story', 'Ticket ' . $n);
        }
        $deleted = 'CCS-111';
        $this->server->on('POST', '/rest/api/3/search/jql', static function (array $body) use (&$issues, $deleted) {
            $jql = (string) $body['jql'];
            if (str_starts_with($jql, 'key in')) {
                preg_match_all('/"([A-Z]+-\d+)"/', $jql, $m);
                if (\in_array($deleted, $m[1], true)) {
                    return FakeAtlassianServer::json(['errorMessages' => [sprintf("An issue with key '%s' does not exist for field 'key'.", $deleted)]], 400);
                }

                return ['issues' => array_values(array_filter($issues, static fn ($i) => \in_array($i['key'], $m[1], true))), 'isLast' => true];
            }

            // JQL réelle : sous-tâches exclues.
            return ['issues' => array_values(array_filter($issues, static fn ($i) => !($i['fields']['issuetype']['subtask'] ?? false))), 'isLast' => true];
        });
        $release = static::getContainer()->get(\App\Service\ReleaseNote\ReleaseImportService::class)->import($project, '10042');
        self::assertCount(130, $release->getTickets());

        // Dans Jira : 20 tickets deviennent des sous-tâches, dont un est ensuite supprimé ; 7 résumés modifiés.
        foreach ($issues as $i => &$issue) {
            if ($i < 20) {
                $issue['fields']['issuetype'] = ['name' => 'Sous-tâche', 'subtask' => true, 'hierarchyLevel' => -1];
            } elseif ($i < 27) {
                $issue['fields']['summary'] .= ' (modifié)';
            }
        }
        unset($issue);
        $issues = array_values(array_filter($issues, static fn ($i) => $i['key'] !== $deleted));
        $this->login();
        $this->server->requests = [];

        $crawler = $this->client->request('GET', '/releases/' . $release->getId() . '/refresh');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sous-tâches retirées de la release (19)');
        self::assertCount(0, $this->server->requestsMatching('GET', '/rest/api/3/issue/'), 'Clé supprimée retirée du lot : aucun appel ticket par ticket');
        self::assertLessThanOrEqual(5, \count($this->server->requests), 'Aperçu : versions + recherche + relecture par lot');

        $before = \count($this->server->requests);
        $this->client->submit($crawler->selectButton('Appliquer l\'actualisation')->form());
        self::assertResponseRedirects();
        self::assertCount($before, $this->server->requests, 'Confirmation : aucune requête Jira');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '7 actualisé(s)');
        self::assertSelectorTextContains('body', '19 sous-tâche(s) retirée(s)');
        self::assertSelectorTextContains('body', '1 inaccessible(s)');
    }
}
