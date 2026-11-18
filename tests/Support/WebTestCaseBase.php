<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

abstract class WebTestCaseBase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected FakeAtlassianServer $server;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Conserve le même conteneur (client HTTP simulé, EntityManager) entre les requêtes.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        // Données Jira mémorisées entre aperçu et confirmation : jamais partagées entre deux tests.
        static::getContainer()->get('cache.jira_snapshot')->clear();

        $this->server = new FakeAtlassianServer();
        /** @var MockHttpClient $http */
        $http = static::getContainer()->get('test.mock_http_client');
        $http->setResponseFactory($this->server);
    }
}
