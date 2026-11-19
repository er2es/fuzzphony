<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** PostgreSQL does not share common subexpressions: the refresh stems each field and normalises each fuzzy field once per row. */
final class RefreshComputesOnceTest extends TestCase
{
    public function testEachFieldVectorAndEachNormalisedTextIsComputedOnce(): void
    {
        $index = Indexes::products();
        $statements = array_values(array_filter((new PostgresSchemaGenerator())->index($index)->statements, static fn(Statement $s): bool => $s->description === 'Builds / removes documents by id'));

        self::assertCount(1, $statements);
        $sql = $statements[0]->sql;
        self::assertSame(count($index->fields), substr_count($sql, 'to_tsvector('), 'one vector per field');
        foreach ($index->fuzzyFields() as $field) {
            self::assertSame(1, substr_count($sql, sprintf('"public"."fuzzphony_norm"(doc."fld_%s"::text)', $field->name)), $field->name);
        }
    }
}
