<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\LabelMapping;
use App\Entity\Project;
use App\Enum\SectionCategory;
use App\Service\ReleaseNote\Dto\LabelRuleSet;
use PHPUnit\Framework\TestCase;

final class LabelRuleSetTest extends TestCase
{
    public function testMappedLabelWinsByPositionAndIgnoredLabelsAreSkipped(): void
    {
        $rules = (new LabelRuleSet())
            ->add('release', null, ignored: true)
            ->add('gestion-client', 'Évolutions de la gestion des clients', SectionCategory::Feature, position: 20)
            ->add('Securite', 'Renforcement de la sécurité', position: 10);

        self::assertSame(
            ['key' => 'securite', 'title' => 'Renforcement de la sécurité', 'category' => null, 'position' => 10],
            $rules->resolve(['release', 'gestion-client', 'SECURITE']),
        );
        self::assertSame('Évolutions de la gestion des clients', $rules->resolve(['zzz', 'Gestion-Client'])['title']);
        self::assertSame(SectionCategory::Feature, $rules->resolve(['gestion-client'])['category']);
    }

    public function testUnmappedLabelFallsBackToLabelItselfAndOnlyIgnoredGivesNull(): void
    {
        $rules = (new LabelRuleSet())->add('release', null, ignored: true);

        $resolved = $rules->resolve(['zeta', 'Alpha']);
        self::assertSame('alpha', $resolved['key']);
        self::assertSame('Alpha', $resolved['title']);
        self::assertNull($rules->resolve(['release']));
        self::assertNull($rules->resolve([]));
    }

    public function testProjectMappingOverridesGlobalOne(): void
    {
        $project = new Project();
        $global = (new LabelMapping())->setLabel('perf')->setTitle('Performances');
        $specific = (new LabelMapping())->setLabel('perf')->setTitle('Temps de réponse du portail')->setProject($project);

        self::assertSame('Temps de réponse du portail', LabelRuleSet::fromMappings([$specific, $global])->resolve(['perf'])['title']);
    }
}
