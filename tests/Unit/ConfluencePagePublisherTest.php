<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Service\Confluence\ConfluenceApiResolver;
use App\Service\Confluence\ConfluenceCloudApi;
use App\Service\Confluence\ConfluencePagePublisher;
use App\Service\Confluence\ConfluenceServerApi;
use App\Service\Confluence\Dto\PagePublishResult;
use App\Tests\Support\FakeAtlassianServer;
use PHPUnit\Framework\TestCase;

final class ConfluencePagePublisherTest extends TestCase
{
    use HttpTestTrait;

    private function publisher(): ConfluencePagePublisher
    {
        $client = $this->client();

        return new ConfluencePagePublisher(new ConfluenceApiResolver([new ConfluenceCloudApi($client), new ConfluenceServerApi($client)]));
    }

    public function testCloudCreatesPageWhenItDoesNotExist(): void
    {
        $publisher = $this->publisher();
        $this->server
            ->on('GET', '/wiki/api/v2/spaces', static fn () => ['results' => [['id' => '777', 'key' => 'CCS', 'name' => 'CCS']]])
            ->on('GET', '/wiki/api/v2/pages', static fn () => ['results' => []])
            ->on('POST', '/wiki/api/v2/pages', static fn (array $b) => ['id' => '1001', 'title' => $b['title'], 'version' => ['number' => 1], '_links' => ['webui' => '/spaces/CCS/pages/1001', 'base' => 'https://acme.atlassian.net/wiki']]);

        $connection = $this->connection(ConnectionProvider::Confluence, DeploymentType::Cloud, 'https://acme.atlassian.net');
        $result = $publisher->publish($connection, 'CCS', '42', null, 'CCS 2.4.0 — Release note IT', '<p>Contenu</p>');

        self::assertSame(PagePublishResult::CREATED, $result->action);
        self::assertSame('1001', $result->page->id);
        self::assertSame('https://acme.atlassian.net/wiki/spaces/CCS/pages/1001', $result->page->url);
        $create = $this->server->requestsMatching('POST', '/wiki/api/v2/pages')[0]['json'];
        self::assertSame(['spaceId' => '777', 'status' => 'current', 'title' => 'CCS 2.4.0 — Release note IT', 'body' => ['representation' => 'storage', 'value' => '<p>Contenu</p>'], 'parentId' => '42'], $create);
    }

    public function testCloudUpdatesKnownPageWithIncrementedVersion(): void
    {
        $publisher = $this->publisher();
        $this->server
            ->on('GET', '/wiki/api/v2/pages/1001', static fn () => ['id' => '1001', 'title' => 'Ancien', 'status' => 'current', 'version' => ['number' => 4]])
            ->on('PUT', '/wiki/api/v2/pages/1001', static fn (array $b) => ['id' => '1001', 'title' => $b['title'], 'version' => $b['version'], '_links' => ['webui' => '/x']]);

        $result = $publisher->publish($this->connection(ConnectionProvider::Confluence, DeploymentType::Cloud, 'https://acme.atlassian.net/wiki'), 'CCS', null, '1001', 'Titre', '<p>v5</p>');

        self::assertSame(PagePublishResult::UPDATED, $result->action);
        self::assertSame(5, $this->server->requestsMatching('PUT', '/pages/1001')[0]['json']['version']['number']);
        self::assertSame(5, $result->page->version);
        self::assertCount(0, $this->server->requestsMatching('POST', '/pages'), 'Aucune création : pas de doublon');
    }

    public function testServerReusesPageWithSameTitleWhenKnownPageWasDeleted(): void
    {
        $publisher = $this->publisher();
        $this->server
            ->on('GET', '/confluence/rest/api/content/900', static fn () => FakeAtlassianServer::json(['message' => 'No content found'], 404))
            ->on('GET', '/confluence/rest/api/content', static fn (array $b, string $url, array $q) => ['results' => [['id' => '901', 'title' => $q['title'], 'version' => ['number' => 2], '_links' => ['webui' => '/display/REL/T']]], '_links' => ['base' => 'https://wiki.local/confluence']])
            ->on('PUT', '/confluence/rest/api/content/901', static fn (array $b) => ['id' => '901', 'title' => $b['title'], 'version' => $b['version'], '_links' => ['webui' => '/display/REL/T', 'base' => 'https://wiki.local/confluence']]);

        $connection = $this->connection(ConnectionProvider::Confluence, DeploymentType::Server, 'https://wiki.local/confluence', AuthenticationType::Bearer);
        $result = $publisher->publish($connection, 'REL', '555', '900', 'T', '<p>x</p>');

        self::assertSame('901', $result->page->id);
        self::assertSame(3, $this->server->requestsMatching('PUT', '/content/901')[0]['json']['version']['number']);
        self::assertSame(['storage' => ['value' => '<p>x</p>', 'representation' => 'storage']], $this->server->requestsMatching('PUT', '/content/901')[0]['json']['body']);
    }

    public function testServerCreatePayloadUsesAncestorsAndSpaceKey(): void
    {
        $publisher = $this->publisher();
        $this->server
            ->on('GET', '/confluence/rest/api/content', static fn () => ['results' => []])
            ->on('POST', '/confluence/rest/api/content', static fn (array $b) => ['id' => '950', 'title' => $b['title'], 'version' => ['number' => 1], '_links' => ['webui' => '/pages/viewpage.action?pageId=950', 'base' => 'https://wiki.local/confluence']]);

        $result = $publisher->publish($this->connection(ConnectionProvider::Confluence, DeploymentType::Server, 'https://wiki.local/confluence', AuthenticationType::Bearer), 'REL', '555', null, 'T', '<p>x</p>');

        self::assertSame(PagePublishResult::CREATED, $result->action);
        $payload = $this->server->requestsMatching('POST', '/rest/api/content')[0]['json'];
        self::assertSame(['key' => 'REL'], $payload['space']);
        self::assertSame([['id' => '555']], $payload['ancestors']);
        self::assertSame('https://wiki.local/confluence/pages/viewpage.action?pageId=950', $result->page->url);
    }

    public function testVersionConflictIsRetriedOnceWithFreshVersion(): void
    {
        $publisher = $this->publisher();
        $reads = 0;
        $this->server
            ->on('GET', '/wiki/api/v2/pages/1001', static function () use (&$reads) {
                ++$reads;

                return ['id' => '1001', 'title' => 'T', 'status' => 'current', 'version' => ['number' => 1 === $reads ? 3 : 6]];
            })
            ->on('PUT', '/wiki/api/v2/pages/1001', static fn (array $b) => 4 === $b['version']['number']
                ? FakeAtlassianServer::json(['errors' => [['title' => 'Version must be incremented']]], 409)
                : ['id' => '1001', 'title' => 'T', 'version' => $b['version']]);

        $result = $publisher->publish($this->connection(ConnectionProvider::Confluence, DeploymentType::Cloud, 'https://acme.atlassian.net'), 'CCS', null, '1001', 'T', '<p/>');

        self::assertSame(7, $result->page->version);
    }
}
