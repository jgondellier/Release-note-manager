<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ExternalConnection;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Service\Jira\AdfTextExtractor;
use App\Service\Jira\JiraIssueNormalizer;
use App\Tests\Support\JiraFixtures;
use PHPUnit\Framework\TestCase;

final class JiraIssueNormalizerTest extends TestCase
{
    private function connection(DeploymentType $type, ?string $epicField = null): ExternalConnection
    {
        return (new ExternalConnection())->setName('J')->setProvider(ConnectionProvider::Jira)->setDeploymentType($type)
            ->setBaseUrl('https://jira.example.com')->setJiraEpicLinkField($epicField);
    }

    public function testCloudIssueWithEpicParentAndAdfDescription(): void
    {
        $raw = JiraFixtures::issue('CCS-12', 'Story', 'Filtrer', [
            'parent' => JiraFixtures::parent('CCS-1', 'Recherche avancée'),
            'description' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bonjour']]],
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'point']]]]]]],
            ]],
        ]);
        $issue = (new JiraIssueNormalizer(new AdfTextExtractor()))->normalize($raw, $this->connection(DeploymentType::Cloud));

        self::assertSame('CCS-12', $issue->key);
        self::assertSame('CCS-1', $issue->parentKey);
        self::assertTrue($issue->parentIsEpic);
        self::assertSame("Bonjour\n\n- point", $issue->description);
        self::assertSame('https://jira.example.com/browse/CCS-12', $issue->url);
        self::assertTrue($issue->hasFixVersion('10042'));
        self::assertSame('done', $issue->statusCategory);
        self::assertSame(['Back-office'], $issue->components);
    }

    public function testServerEpicLinkUsesTheConfiguredCustomField(): void
    {
        $raw = JiraFixtures::issue('FIN-7', 'Bug', 'Anomalie', ['customfield_10100' => 'FIN-1', 'customfield_10014' => 'FIN-999', 'description' => 'Texte wiki']);
        $normalizer = new JiraIssueNormalizer(new AdfTextExtractor());

        self::assertSame('FIN-1', $normalizer->normalize($raw, $this->connection(DeploymentType::Server, 'customfield_10100'))->epicLinkKey);
        self::assertNull($normalizer->normalize($raw, $this->connection(DeploymentType::Server))->epicLinkKey, 'Aucun identifiant de champ supposé par défaut');
        self::assertContains('customfield_10100', JiraIssueNormalizer::fields($this->connection(DeploymentType::Server, 'customfield_10100')));
    }

    public function testEpicTypeDetectedByNameOnServer(): void
    {
        $raw = JiraFixtures::issue('FIN-1', 'Épopée', 'Mon epic', ['issuetype' => ['name' => 'Épopée', 'subtask' => false]]);
        self::assertTrue((new JiraIssueNormalizer(new AdfTextExtractor()))->normalize($raw, $this->connection(DeploymentType::Server))->isEpic);
    }
}
