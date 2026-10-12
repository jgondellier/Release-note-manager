<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ExternalConnection;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\HttpClientOptionsProvider;
use App\Service\Security\SecretEncryptionService;
use App\Tests\Support\FakeAtlassianServer;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

trait HttpTestTrait
{
    private FakeAtlassianServer $server;
    private SecretEncryptionService $encryption;

    private function client(): AtlassianApiClient
    {
        $this->server ??= new FakeAtlassianServer();
        $this->encryption ??= new SecretEncryptionService(SecretEncryptionService::generateKey());

        return new AtlassianApiClient(
            new MockHttpClient($this->server),
            $this->encryption,
            new HttpClientOptionsProvider('', '', '', true, 5, 10),
            new NullLogger(),
        );
    }

    private function connection(ConnectionProvider $provider, DeploymentType $type, string $baseUrl, AuthenticationType $auth = AuthenticationType::Basic, string $secret = 'TOKEN-SECRET-123'): ExternalConnection
    {
        $this->encryption ??= new SecretEncryptionService(SecretEncryptionService::generateKey());

        return (new ExternalConnection())
            ->setName('Test')->setProvider($provider)->setDeploymentType($type)->setBaseUrl($baseUrl)
            ->setAuthenticationType($auth)->setUsername(AuthenticationType::Basic === $auth ? 'robot@example.com' : null)
            ->setEncryptedSecret($this->encryption->encrypt($secret));
    }
}
