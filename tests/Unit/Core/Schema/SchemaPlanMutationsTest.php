<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Schema;

use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use PHPUnit\Framework\TestCase;

final class SchemaPlanMutationsTest extends TestCase
{
    public function testToSqlWritesACommentAndASemicolonTerminatedStatementEach(): void
    {
        $plan = new SchemaPlan([
            new Statement("CREATE TABLE a (id int);\n", 'first'),
            new Statement('CREATE INDEX CONCURRENTLY i ON a (id)', 'second', false),
        ]);

        self::assertSame(
            "-- first\nCREATE TABLE a (id int);\n\n"
            . "-- second (run outside a transaction)\nCREATE INDEX CONCURRENTLY i ON a (id);\n",
            $plan->toSql(),
        );
    }

    public function testToSqlOfAnEmptyPlanIsJustANewline(): void
    {
        self::assertSame("\n", (new SchemaPlan())->toSql());
    }
}
