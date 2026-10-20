<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Jira\Dto\JiraComment;
use App\Service\Jira\JiraCommentService;
use PHPUnit\Framework\TestCase;

final class JiraCommentFilterTest extends TestCase
{
    public function testPrefixMatchingIsTolerantButStrict(): void
    {
        $comments = [
            new JiraComment('1', null, null, 'TEST : scénario nominal OK'),
            new JiraComment('2', null, null, 'test:sans espace'),
            new JiraComment('3', null, null, '*TEST :* en gras (wiki Jira)'),
            new JiraComment('4', null, null, '  TEST  :  espaces multiples'),
            new JiraComment('5', null, null, 'Remarque : pas un test'),
            new JiraComment('6', null, null, 'TESTING en cours'),
            new JiraComment('7', null, null, 'Ce TEST : au milieu ne compte pas'),
        ];

        $kept = JiraCommentService::filterByPrefix($comments, 'TEST :');

        self::assertSame(['1', '2', '3', '4'], array_map(static fn ($c) => $c->id, $kept));
        self::assertSame(['scénario nominal OK', 'sans espace', 'en gras (wiki Jira)', 'espaces multiples'], array_map(static fn ($c) => $c->body, $kept));
        self::assertCount(1, JiraCommentService::filterByPrefix([new JiraComment('8', null, null, 'RECETTE : ok')], 'RECETTE :'));
    }
}
