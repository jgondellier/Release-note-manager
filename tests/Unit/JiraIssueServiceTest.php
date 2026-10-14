<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Service\Jira\AdfTextExtractor;
use App\Service\Jira\Dto\JiraVersion;
use App\Service\Jira\JiraIssueNormalizer;
use App\Service\Jira\JiraIssueService;
use App\Tests\Support\JiraFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class JiraIssueServiceTest extends TestCase
{
    use HttpTestTrait;

    public function testServerPaginationUsesStartAtAndKeepsOnlySelectedVersion(): void
    {
        $client = $this->client();
        $all = [];
        for ($i = 1; $i <= 7; ++$i) {
            $all[] = JiraFixtures::issue('FIN-' . $i, 'Story', 'T' . $i);
        }
        // Ticket renvoyé à tort par Jira avec une autre version : doit être écarté.
        $all[] = JiraFixtures::issue('FIN-99', 'Story', 'Autre version', [], '20000');

        $this->server->on('GET', '/rest/api/2/search', static function (array $body, string $url, array $query) use ($all) {
            // L'instance plafonne à 3 résultats par page, quel que soit maxResults.
            $startAt = (int) $query['startAt'];

            return ['startAt' => $startAt, 'maxResults' => 3, 'total' => \count($all), 'issues' => \array_slice($all, $startAt, 3)];
        });

        $connection = $this->connection(ConnectionProvider::Jira, DeploymentType::Server, 'https://jira.local', AuthenticationType::Bearer);
        $project = (new Project())->setName('Finagora')->setCode('FIN')->setJiraConnection($connection)->setJiraProjectKey('FIN');
        $service = new JiraIssueService($client, new JiraIssueNormalizer(new AdfTextExtractor()), new NullLogger());

        $issues = $service->findByFixVersion($project, new JiraVersion('10042', '2.4.0', null, false, false, null, null));

        self::assertCount(7, $issues);
        $queries = array_map(static function ($r) {
            parse_str((string) parse_url($r['url'], PHP_URL_QUERY), $q);

            return $q;
        }, $this->server->requests);
        self::assertSame(['0', '3', '6'], array_column($queries, 'startAt'));
        self::assertStringContainsString('fixVersion = 10042', $queries[0]['jql']);
        self::assertStringContainsString('project = "FIN"', $queries[0]['jql']);
        self::assertArrayNotHasKey('validateQuery', $queries[0], 'Option non portable entre versions de Jira Server');
        self::assertStringContainsString('fixVersions', $queries[0]['fields']);
        self::assertNotContains('FIN-99', array_map(static fn ($i) => $i->key, $issues));
    }
}
