<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCaseBase;

final class SecurityTest extends WebTestCaseBase
{
    /** @return iterable<array{string, string}> */
    public static function protectedRoutes(): iterable
    {
        yield ['GET', '/'];
        yield ['GET', '/projects'];
        yield ['GET', '/connections/new'];
        yield ['GET', '/releases'];
        yield ['GET', '/settings/issue-types'];
        yield ['POST', '/releases/1/publish/execute'];
        yield ['POST', '/publications/1/retry'];
        yield ['POST', '/connections/1/test'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRoutes')]
    public function testAnonymousAccessIsRedirectedToLogin(string $method, string $url): void
    {
        $this->client->request($method, $url);
        self::assertResponseRedirects('/login');
    }

    public function testWrongTokenIsRejectedWithGenericMessage(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_admin_token' => 'mauvais-token']));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Identifiants invalides');
        self::assertStringNotContainsString($this->adminToken(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/login', ['_admin_token' => $this->adminToken()]);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testValidTokenOpensSessionAndLogoutClosesIt(): void
    {
        $this->login();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Déconnexion')->form());
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testPostActionsRequireCsrfToken(): void
    {
        $this->login();
        $this->client->request('POST', '/projects/1/check');
        // Projet inexistant : 404 avant même la vérification CSRF ; on teste une action sur une ressource réelle.
        $this->client->request('GET', '/connections/new');
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form([
            'connection[name]' => 'Jira',
            'connection[provider]' => 'jira',
            'connection[deploymentType]' => 'server',
            'connection[baseUrl]' => 'https://jira.local',
            'connection[authenticationType]' => 'bearer',
            'connection[plainSecret]' => 'PAT',
        ]);
        $this->client->submit($form);
        $this->client->catchExceptions(true);
        $this->client->request('POST', '/connections/1/test', ['_token' => 'invalide']);
        self::assertResponseStatusCodeSame(403);
    }
}
