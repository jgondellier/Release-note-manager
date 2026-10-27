<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ExternalConnection;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Enum\SectionCategory;
use App\Enum\SectionSourceType;
use App\Service\Jira\AdfTextExtractor;
use App\Service\Jira\Dto\EpicReference;
use App\Service\Jira\JiraIssueNormalizer;
use App\Service\ReleaseNote\Dto\IssueTypeRule;
use App\Service\ReleaseNote\Dto\IssueTypeRuleSet;
use App\Service\ReleaseNote\Dto\LabelRuleSet;
use App\Service\ReleaseNote\SectionBuilder;
use App\Tests\Support\JiraFixtures;
use PHPUnit\Framework\TestCase;

final class SectionBuilderTest extends TestCase
{
    private function rules(): IssueTypeRuleSet
    {
        return (new IssueTypeRuleSet())
            ->add('Bug', new IssueTypeRule('Corrections', SectionCategory::Fix, 20))
            ->add('Bogue', new IssueTypeRule('Corrections', SectionCategory::Fix, 20))
            ->add('Story', new IssueTypeRule('Évolutions', SectionCategory::Feature, 10))
            ->add('Task', new IssueTypeRule('Tâches techniques', SectionCategory::Technical, 30))
            ->add('*', new IssueTypeRule('Autres', SectionCategory::Other, 90));
    }

    private function issues(array $raws): array
    {
        $connection = (new ExternalConnection())->setName('J')->setProvider(ConnectionProvider::Jira)->setDeploymentType(DeploymentType::Cloud)->setBaseUrl('https://j');
        $normalizer = new JiraIssueNormalizer(new AdfTextExtractor());

        return array_map(static fn ($r) => $normalizer->normalize($r, $connection), $raws);
    }

    public function testTicketsAreGroupedByEpicThenByType(): void
    {
        $issues = $this->issues([
            JiraFixtures::issue('CCS-3', 'Bug', 'Bug sans epic'),
            JiraFixtures::issue('CCS-4', 'bogue', 'Bogue en minuscules'),
            JiraFixtures::issue('CCS-5', 'Story', 'Story epic A'),
            JiraFixtures::issue('CCS-6', 'Bug', 'Bug epic A'),
            JiraFixtures::issue('CCS-7', 'Task', 'Tâche'),
            JiraFixtures::issue('CCS-8', 'Spike', 'Type inconnu'),
            JiraFixtures::issue('CCS-9', 'Story', 'Story epic B'),
        ]);
        $epics = [
            'CCS-5' => new EpicReference('CCS-1', 'Zeta : paiement'),
            'CCS-6' => new EpicReference('CCS-1', 'Zeta : paiement'),
            'CCS-9' => new EpicReference('CCS-2', 'Alpha : recherche'),
        ];

        $plans = (new SectionBuilder())->build($issues, $epics, $this->rules());
        $summary = array_map(static fn ($p) => [$p->sourceType, $p->title, $p->issueKeys], $plans);

        self::assertSame([
            [SectionSourceType::Epic, 'Alpha : recherche', ['CCS-9']],
            [SectionSourceType::Epic, 'Zeta : paiement', ['CCS-5', 'CCS-6']],
            [SectionSourceType::IssueType, 'Corrections', ['CCS-3', 'CCS-4']],
            [SectionSourceType::IssueType, 'Tâches techniques', ['CCS-7']],
            [SectionSourceType::IssueType, 'Autres', ['CCS-8']],
        ], $summary);
        self::assertSame('CCS-1', $plans[1]->sourceKey);
    }

    public function testEpicSectionCategoryFollowsMajorityOfTickets(): void
    {
        $issues = $this->issues([
            JiraFixtures::issue('CCS-5', 'Bug', 'b1'),
            JiraFixtures::issue('CCS-6', 'Bug', 'b2'),
            JiraFixtures::issue('CCS-7', 'Story', 's'),
        ]);
        $epic = new EpicReference('CCS-1', 'Epic');
        $plans = (new SectionBuilder())->build($issues, ['CCS-5' => $epic, 'CCS-6' => $epic, 'CCS-7' => $epic], $this->rules());

        self::assertCount(1, $plans);
        self::assertSame(SectionCategory::Fix, $plans[0]->category);
    }

    public function testFallbackSectionWhenNoRuleMatches(): void
    {
        $plans = (new SectionBuilder())->build($this->issues([JiraFixtures::issue('CCS-1', 'Spike', 's')]), [], new IssueTypeRuleSet());
        self::assertSame(IssueTypeRuleSet::FALLBACK_TITLE, $plans[0]->title);
    }

    public function testTicketsWithoutEpicAreGroupedByLabelBeforeType(): void
    {
        $issues = $this->issues([
            JiraFixtures::issue('CCS-2', 'Story', 'Epic gagne', ['labels' => ['gestion-client']]),
            JiraFixtures::issue('CCS-3', 'Bug', 'Bug client', ['labels' => ['release', 'gestion-client']]),
            JiraFixtures::issue('CCS-4', 'Story', 'Story client', ['labels' => ['gestion-client']]),
            JiraFixtures::issue('CCS-5', 'Task', 'Libellé brut', ['labels' => ['batch-nuit']]),
            JiraFixtures::issue('CCS-6', 'Bug', 'Seulement ignorée', ['labels' => ['release']]),
            JiraFixtures::issue('CCS-7', 'Bug', 'Sans étiquette'),
        ]);
        $rules = $this->rules()->withLabels((new LabelRuleSet())
            ->add('release', null, ignored: true)
            ->add('gestion-client', 'Évolutions de la gestion des clients', position: 10));

        $builder = new SectionBuilder();
        $plans = $builder->build($issues, ['CCS-2' => new EpicReference('CCS-1', 'Epic')], $rules);
        $summary = array_map(static fn ($p) => [$p->sourceType, $p->sourceKey, $p->title, $p->issueKeys], $plans);

        self::assertSame([
            [SectionSourceType::Epic, 'CCS-1', 'Epic', ['CCS-2']],
            [SectionSourceType::Label, 'gestion-client', 'Évolutions de la gestion des clients', ['CCS-3', 'CCS-4']],
            [SectionSourceType::Label, 'batch-nuit', 'batch-nuit', ['CCS-5']],
            [SectionSourceType::IssueType, 'Corrections', 'Corrections', ['CCS-6', 'CCS-7']],
        ], $summary);
        // Catégorie non imposée par la table : déduite des tickets (tâche → technique).
        self::assertSame(SectionCategory::Technical, $plans[2]->category);

        $plan = $builder->planFor($issues[2], null, $rules);
        self::assertSame([SectionSourceType::Label, 'gestion-client'], [$plan->sourceType, $plan->sourceKey]);
    }
}
