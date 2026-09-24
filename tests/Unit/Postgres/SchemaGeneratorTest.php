<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    public function testSidecarColumnsFollowTheDefinition(): void
    {
        self::assertSame(
            ['id', 'tsv', 'fz', 'exact', 'boost', 'recency_at', 'f_price', 'f_in_stock', 'f_published_at', 'indexed_at'],
            array_keys((new PostgresSchemaGenerator())->columns(Indexes::products())),
        );
    }

    public function testIndexesAreBuiltConcurrentlyOutsideTransactions(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $concurrent = array_values(array_filter($statements, static fn(Statement $s): bool => str_contains($s->sql, 'CONCURRENTLY')));

        self::assertCount(5, $concurrent); // tsv, trigram, 3 filters
        foreach ($concurrent as $statement) {
            self::assertFalse($statement->transactional);
        }
        self::assertStringContainsString('USING gin (fz "public".gin_trgm_ops)', $concurrent[1]->sql);
    }

    public function testQueueModeEnqueuesAndTriggerModeRefreshes(): void
    {
        $queue = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();
        $trigger = (new PostgresSchemaGenerator())->index(Indexes::products('trigger'))->toSql();
        $manual = (new PostgresSchemaGenerator())->index(Indexes::products('manual'))->toSql();

        self::assertStringContainsString('INSERT INTO fuzzphony_queue', $queue);
        self::assertStringContainsString('PERFORM "fuzzphony_refresh_products"', $trigger);
        self::assertStringNotContainsString('CREATE OR REPLACE TRIGGER', $manual);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand" ON "fz_brand"', $manual);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_upd" ON "fz_brand"', $manual);
    }

    public function testStatementLevelTriggersUseTransitionTables(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();

        self::assertStringContainsString('FROM fz_new AS r CROSS JOIN LATERAL (SELECT id FROM fz_product WHERE brand_id = r."id")', $sql);
        self::assertStringContainsString('AFTER UPDATE ON "fz_brand" REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT', $sql);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand" ON "fz_brand"', $sql, 'row-level trigger of a previous setup is removed');
    }

    public function testRowLevelTriggers(): void
    {
        $definition = Indexes::products('queue')->with(triggerLevel: TriggerLevel::Row);
        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString('SELECT id FROM fz_product WHERE brand_id = NEW."id"', $sql);
        self::assertStringContainsString('SELECT id FROM fz_product WHERE brand_id = OLD."id"', $sql);
        self::assertStringContainsString('AFTER INSERT OR UPDATE OR DELETE ON "fz_brand" FOR EACH ROW', $sql);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_ins"', $sql);
    }

    public function testRefreshFunctionUpsertsAndDeletesMissingDocuments(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('SELECT DISTINCT ON (doc.fz_id)', $sql);
        self::assertStringContainsString("setweight(to_tsvector('fuzzphony_english'::regconfig, coalesce(doc.\"fld_name\"::text, '')), 'A')", $sql);
        self::assertStringContainsString('ON CONFLICT (id) DO UPDATE SET', $sql);
        self::assertStringContainsString('AND NOT EXISTS (SELECT 1 FROM', $sql);
    }

    public function testGlobalSchemaCreatesOneTextConfigPerLanguage(): void
    {
        $german = IndexDefinition::builder('articles')->fromTable('article')->field('title')->language('german')->build();
        $sql = (new PostgresSchemaGenerator())->global(Indexes::products(), Indexes::products(), $german)->toSql();

        self::assertSame(1, substr_count($sql, 'CREATE TEXT SEARCH CONFIGURATION "fuzzphony_english"'));
        self::assertStringContainsString('WITH "public".unaccent, "german_stem"', $sql);
    }

    public function testLongNamesStayWithinPostgresLimits(): void
    {
        $name = str_repeat('x', 48);
        $definition = IndexDefinition::builder($name)->fromTable('a_really_long_table_name_for_testing')->field('title')->build();
        $generator = new PostgresSchemaGenerator();

        self::assertLessThanOrEqual(63, strlen($generator->syncFunctionName($definition, $definition->effectiveWatches()[0])));
        foreach (array_keys($generator->indexes($definition)) as $index) {
            self::assertLessThanOrEqual(63, strlen($index));
        }
    }

    public function testDropNeverTouchesTheSource(): void
    {
        $sql = (new PostgresSchemaGenerator())->drop(Indexes::products())->toSql();

        self::assertStringContainsString('DROP TABLE IF EXISTS "fuzzphony_products"', $sql);
        self::assertStringNotContainsString('DROP TABLE IF EXISTS "fz_product"', $sql);
    }
}
