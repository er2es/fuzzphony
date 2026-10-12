<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\TestCase;

/** The search log table is part of the shared schema only when the log is switched on. */
final class SchemaGeneratorSearchLogTest extends TestCase
{
    /** @return list<Statement> the statements that mention the search log */
    private static function logStatements(bool $searchLog): array
    {
        $plan = (new PostgresSchemaGenerator(new Names(), searchLog: $searchLog))->global();

        return array_values(array_filter($plan->statements, static fn(Statement $s): bool => str_contains($s->sql, 'search_log')));
    }

    public function testWithoutTheLogTheSharedSchemaHasNoSearchLogStatement(): void
    {
        self::assertSame([], self::logStatements(false));
    }

    public function testTheLogIsOffByDefault(): void
    {
        $default = (new PostgresSchemaGenerator())->global();

        self::assertEquals((new PostgresSchemaGenerator(new Names(), searchLog: false))->global(), $default);
    }

    public function testWithTheLogTheSharedSchemaCreatesTheTableAndItsTwoIndexes(): void
    {
        $statements = self::logStatements(true);

        self::assertCount(3, $statements);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS "public"."fuzzphony_search_log"', $statements[0]->sql);
        self::assertSame('Search log (opt-in)', $statements[0]->description);
        self::assertSame(
            'CREATE INDEX IF NOT EXISTS fuzzphony_search_log_index_at ON "public"."fuzzphony_search_log" (index_name, at)',
            $statements[1]->sql,
        );
        self::assertSame('Search log by index and time', $statements[1]->description);
        self::assertSame(
            'CREATE INDEX IF NOT EXISTS fuzzphony_search_log_index_norm ON "public"."fuzzphony_search_log" (index_name, query_norm)',
            $statements[2]->sql,
        );
        self::assertSame('Search log grouped by query', $statements[2]->description);
        foreach (['at timestamptz NOT NULL', 'index_name text NOT NULL', 'tenant text', 'actor_user text', 'query text NOT NULL', 'query_norm text NOT NULL', 'hits integer NOT NULL', 'took_ms real NOT NULL', 'used_fuzzy boolean NOT NULL', 'status text NOT NULL'] as $column) {
            self::assertStringContainsString($column, $statements[0]->sql);
        }
    }

    public function testTheTableFollowsTheConfiguredSchema(): void
    {
        $plan = (new PostgresSchemaGenerator(new Names('ext', 'fz'), searchLog: true))->global();
        $create = array_values(array_filter($plan->statements, static fn(Statement $s): bool => str_starts_with($s->sql, 'CREATE TABLE IF NOT EXISTS "fz"."fuzzphony_search_log"')));

        self::assertCount(1, $create);
        self::assertSame('"fz"."fuzzphony_search_log"', (new Names('ext', 'fz'))->searchLog());
        self::assertSame('fuzzphony_search_log_index_at', (new Names())->searchLogIndexName('index_at'));
    }
}
