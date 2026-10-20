<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Jira\OctaneCoverageService;
use PHPUnit\Framework\TestCase;

final class OctaneCoverageNormalizeTest extends TestCase
{
    /** Réponse au format de l'extension (objets sous « fields », cf. jira-octane-plugin-coverage-panel.js). */
    public function testFieldsShapeAsReturnedByThePlugin(): void
    {
        $c = OctaneCoverageService::normalize([
            'issueKey' => 'AER_DCER-537',
            'status' => 'hasData',
            'octaneEntity' => ['fields' => ['id' => '4012', 'name' => 'Fiche client', 'url' => 'https://octane.local/ui/entity/4012', 'testTabUrl' => 'https://octane.local/ui/entity/4012/tests', 'typeAbbreviation' => 'US']],
            'totalTestsCount' => 6,
            'totalExecutedTestsCount' => 5,
            'coverageGroups' => [
                ['fields' => ['id' => 'failed', 'name' => 'Failed', 'count' => 1, 'percentage' => '20%', 'order' => 2]],
                ['fields' => ['id' => 'passed', 'name' => 'Passed', 'count' => 4, 'percentage' => '80%', 'order' => 1]],
                ['fields' => ['id' => 'skipped', 'name' => 'Skipped', 'count' => 0, 'order' => 5]],
            ],
        ]);

        self::assertSame('hasData', $c['status']);
        self::assertSame(['id' => '4012', 'name' => 'Fiche client', 'url' => 'https://octane.local/ui/entity/4012', 'testTabUrl' => 'https://octane.local/ui/entity/4012/tests'], $c['entity']);
        self::assertSame(6, $c['totalTests']);
        self::assertSame(5, $c['executedTests']);
        self::assertSame(['passed', 'failed'], array_column($c['groups'], 'id'), 'Ordre Octane, groupes vides ignorés');
    }

    public function testFlatShapeAndUnsafeUrlsAreHandled(): void
    {
        $c = OctaneCoverageService::normalize([
            'status' => 'hasData',
            'octaneEntity' => ['id' => 7, 'name' => 'X', 'testTabUrl' => 'javascript:alert(1)'],
            'totalTestsCount' => 2,
            'coverageGroups' => [['id' => 'passed', 'name' => 'Passed', 'count' => 2]],
        ]);
        self::assertSame('7', $c['entity']['id']);
        self::assertNull($c['entity']['testTabUrl'], 'Seules les URL http(s) sont conservées');
        self::assertSame(2, $c['executedTests']);

        self::assertSame('noData', OctaneCoverageService::normalize(['status' => 'noData'])['status']);
    }

    public function testAggregateSumsOnlyEntitiesWithData(): void
    {
        $a = OctaneCoverageService::aggregate([
            ['status' => 'hasData', 'totalTests' => 3, 'executedTests' => 3, 'groups' => [['id' => 'passed', 'name' => 'Passed', 'count' => 3, 'percentage' => null]]],
            ['status' => 'hasData', 'totalTests' => 4, 'executedTests' => 2, 'groups' => [['id' => 'passed', 'name' => 'Passed', 'count' => 1, 'percentage' => null], ['id' => 'failed', 'name' => 'Failed', 'count' => 1, 'percentage' => null]]],
            ['status' => 'noData', 'totalTests' => 0, 'executedTests' => 0, 'groups' => []],
        ]);
        self::assertSame(2, $a['withData']);
        self::assertSame(7, $a['totalTests']);
        self::assertSame(5, $a['executedTests']);
        self::assertSame([['id' => 'passed', 'name' => 'Passed', 'count' => 4], ['id' => 'failed', 'name' => 'Failed', 'count' => 1]], $a['groups']);
    }
}
