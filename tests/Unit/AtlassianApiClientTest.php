<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Service\Http\ExternalApiException;
use App\Tests\Support\FakeAtlassianServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AtlassianApiClientTest extends TestCase
{
    use HttpTestTrait;

    public function testBasicAndBearerAuthenticationHeaders(): void
    {
        $client = $this->client();
        $this->server->on('GET', '/rest/api/2/myself', static fn () => ['name' => 'robot']);

        $client->get($this->connection(ConnectionProvider::Jira, DeploymentType::Server, 'https://jira.local', AuthenticationType::Bearer, 'PAT-1'), '/rest/api/2/myself');
        $client->get($this->connection(ConnectionProvider::Jira, DeploymentType::Server, 'https://jira.local', AuthenticationType::Basic, 'pwd'), '/rest/api/2/myself');

        $bearer = implode("\n", $this->server->requests[0]['headers']);
        $basic = implode("\n", $this->server->requests[1]['headers']);
        self::assertStringContainsString('Authorization: Bearer PAT-1', $bearer);
        self::assertStringContainsString('Authorization: Basic ' . base64_encode('robot@example.com:pwd'), $basic);
    }

    /** @return iterable<string, array{int, string}> */
    public static function errors(): iterable
    {
        yield '401' => [401, ExternalApiException::AUTHENTICATION];
        yield '403' => [403, ExternalApiException::FORBIDDEN];
        yield '404' => [404, ExternalApiException::NOT_FOUND];
        yield '409' => [409, ExternalApiException::CONFLICT];
        yield '429' => [429, ExternalApiException::RATE_LIMITED];
        yield '503' => [503, ExternalApiException::UNAVAILABLE];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testHttpErrorsAreMappedWithoutLeakingSecrets(int $status, string $category): void
    {
        $client = $this->client();
        // L'API renvoie (à tort) le token dans son message d'erreur : il doit être masqué.
        $this->server->on('GET', '/x', static fn () => FakeAtlassianServer::json(['errorMessages' => ['Token TOKEN-SECRET-123 refusé']], $status));

        try {
            $client->get($this->connection(ConnectionProvider::Jira, DeploymentType::Cloud, 'https://acme.atlassian.net'), '/x');
            self::fail('Exception attendue');
        } catch (ExternalApiException $e) {
            self::assertSame($category, $e->getCategory());
            self::assertSame($status, $e->getHttpStatus());
            self::assertStringNotContainsString('TOKEN-SECRET-123', $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testTimeoutAndInvalidResponses(): void
    {
        $client = $this->client();
        $connection = $this->connection(ConnectionProvider::Jira, DeploymentType::Cloud, 'https://acme.atlassian.net');
        $this->server->on('GET', '/timeout', static fn () => new MockResponse([''], ['error' => 'Idle timeout reached for "https://acme.atlassian.net/timeout".']));
        $this->server->on('GET', '/html', static fn () => new MockResponse('<html>login</html>', ['response_headers' => ['content-type' => 'text/html']]));
        $this->server->on('GET', '/broken', static fn () => new MockResponse('{oops', ['response_headers' => ['content-type' => 'application/json']]));

        foreach (['/timeout' => ExternalApiException::TIMEOUT, '/html' => ExternalApiException::INVALID_RESPONSE, '/broken' => ExternalApiException::INVALID_RESPONSE] as $path => $category) {
            try {
                $client->get($connection, $path);
                self::fail('Exception attendue pour ' . $path);
            } catch (ExternalApiException $e) {
                self::assertSame($category, $e->getCategory(), $path);
            }
        }
    }

    public function testInactiveConnectionOrMissingSecretIsRefusedBeforeAnyCall(): void
    {
        $client = $this->client();
        $inactive = $this->connection(ConnectionProvider::Jira, DeploymentType::Cloud, 'https://a.atlassian.net')->setActive(false);
        $noSecret = $this->connection(ConnectionProvider::Jira, DeploymentType::Cloud, 'https://a.atlassian.net')->setEncryptedSecret(null);

        foreach ([$inactive, $noSecret] as $connection) {
            try {
                $client->get($connection, '/x');
                self::fail('Exception attendue');
            } catch (ExternalApiException $e) {
                self::assertSame(ExternalApiException::CONFIGURATION, $e->getCategory());
            }
        }
        self::assertSame([], $this->server->requests);
    }
}
