<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ExternalConnection;
use App\Entity\IssueTypeMapping;
use App\Entity\Project;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\SectionCategory;
use App\Service\Security\SecretEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Base des tests d'intégration : schéma SQLite recréé à chaque test,
 * client HTTP simulé (FakeAtlassianServer), données de référence.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected FakeAtlassianServer $server;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->server = new FakeAtlassianServer();
        /** @var MockHttpClient $client */
        $client = self::getContainer()->get('test.mock_http_client');
        $client->setResponseFactory($this->server);
    }

    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }

    protected function createConnection(ConnectionProvider $provider, DeploymentType $type, string $baseUrl, string $name): ExternalConnection
    {
        /** @var SecretEncryptionService $encryption */
        $encryption = self::getContainer()->get(SecretEncryptionService::class);
        $connection = (new ExternalConnection())
            ->setName($name)
            ->setProvider($provider)
            ->setDeploymentType($type)
            ->setBaseUrl($baseUrl)
            ->setAuthenticationType(DeploymentType::Cloud === $type ? AuthenticationType::Basic : AuthenticationType::Bearer)
            ->setUsername(DeploymentType::Cloud === $type ? 'robot@example.com' : null)
            ->setEncryptedSecret($encryption->encrypt('secret-' . str_replace(' ', '-', $name)));
        $this->em->persist($connection);

        return $connection;
    }

    /** Projet complet : Jira Cloud + Confluence Cloud + Confluence Server. */
    protected function createProject(bool $withServer = true): Project
    {
        $jira = $this->createConnection(ConnectionProvider::Jira, DeploymentType::Cloud, 'https://acme.atlassian.net', 'Jira Cloud');
        $cloud = $this->createConnection(ConnectionProvider::Confluence, DeploymentType::Cloud, 'https://acme.atlassian.net', 'Confluence Cloud');
        $project = (new Project())
            ->setName('CCS')->setCode('CCS')
            ->setJiraConnection($jira)->setJiraProjectKey('CCS')
            ->setConfluenceCloudConnection($cloud)->setConfluenceCloudSpaceKey('CCS');
        if ($withServer) {
            $server = $this->createConnection(ConnectionProvider::Confluence, DeploymentType::Server, 'https://wiki.interne.local/confluence', 'Confluence DC');
            $project->setConfluenceServerConnection($server)->setConfluenceServerSpaceKey('REL')->setConfluenceServerParentPageId('555');
        }
        $this->em->persist($project);

        foreach ([['Bug', 'Corrections', SectionCategory::Fix, 20], ['Story', 'Évolutions', SectionCategory::Feature, 10], ['Task', 'Tâches techniques', SectionCategory::Technical, 30], ['*', 'Autres changements', SectionCategory::Other, 90]] as [$type, $title, $cat, $pos]) {
            $this->em->persist((new IssueTypeMapping())->setJiraIssueType($type)->setSectionTitle($title)->setCategory($cat)->setPosition($pos));
        }
        $this->em->flush();

        return $project;
    }

    /**
     * Simule Jira Cloud : versions du projet + recherche JQL renvoyant $issues
     * (en 2 pages si plus de 2 tickets, pour exercer la pagination par nextPageToken).
     *
     * @param list<array<string, mixed>> $issues
     */
    protected function fakeJiraCloud(array &$issues): void
    {
        $this->server
            ->on('GET', '/rest/api/3/project/CCS/versions', static fn () => JiraFixtures::versions())
            ->on('POST', '/rest/api/3/search/jql', static function (array $body) use (&$issues) {
                $jql = (string) $body['jql'];
                if (str_starts_with($jql, 'key in')) {
                    preg_match_all('/"([A-Z]+-\d+)"/', $jql, $m);
                    $found = array_values(array_filter($issues, static fn ($i) => \in_array($i['key'], $m[1], true)));

                    return ['issues' => $found, 'isLast' => true];
                }
                preg_match('/fixVersion = (\d+)/', $jql, $v);
                $matching = array_values(array_filter($issues, static fn ($i) => \in_array($v[1] ?? '', array_column($i['fields']['fixVersions'], 'id'), true)));
                $page = isset($body['nextPageToken']) ? (int) substr((string) $body['nextPageToken'], 5) : 0;
                $chunk = \array_slice($matching, $page * 2, 2);
                $isLast = ($page + 1) * 2 >= \count($matching);

                return ['issues' => $chunk, 'isLast' => $isLast] + ($isLast ? [] : ['nextPageToken' => 'page-' . ($page + 1)]);
            });
    }
}
