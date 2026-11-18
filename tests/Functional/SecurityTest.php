<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\WebTestCaseBase;

/**
 * Pas d'authentification (usage local, comme WSUse) : les écrans s'ouvrent directement,
 * les actions de modification restent protégées par un jeton CSRF.
 */
final class SecurityTest extends WebTestCaseBase
{
    /** @return iterable<array{string}> */
    public static function screens(): iterable
    {
        yield ['/'];
        yield ['/projects'];
        yield ['/connections/new'];
        yield ['/releases'];
        yield ['/settings/issue-types'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testScreensOpenWithoutLogin(string $url): void
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testLoginAndLogoutNoLongerExist(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertCount(0, $crawler->selectButton('Déconnexion'));

        $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostActionsRequireCsrfToken(): void
    {
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
