<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Jira\JiraJqlBuilder;
use PHPUnit\Framework\TestCase;

final class JiraJqlBuilderTest extends TestCase
{
    public function testFixVersionQueryTargetsOnlyTheSelectedVersionById(): void
    {
        self::assertSame(
            'project = "CCS" AND fixVersion = 10042 ORDER BY issuetype ASC, key ASC',
            JiraJqlBuilder::forFixVersion('CCS', '10042')
        );
    }

    public function testVersionNamesAreEscaped(): void
    {
        $jql = JiraJqlBuilder::forFixVersion('CCS', 'v2 "beta" \\ final');
        self::assertSame('project = "CCS" AND fixVersion = "v2 \\"beta\\" \\\\ final" ORDER BY issuetype ASC, key ASC', $jql);
    }

    public function testKeyQueryIgnoresInvalidKeys(): void
    {
        self::assertSame('key in ("CCS-1", "FIN-22") ORDER BY key ASC', JiraJqlBuilder::forKeys(['CCS-1', 'FIN-22', 'x" OR 1=1', 'CCS-1']));
        $this->expectException(\InvalidArgumentException::class);
        JiraJqlBuilder::forKeys(['DROP TABLE']);
    }
}
