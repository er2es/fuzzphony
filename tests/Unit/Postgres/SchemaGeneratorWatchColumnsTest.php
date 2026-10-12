<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which columns of a watched table an UPDATE must change, and how the trigger statements are described. */
final class SchemaGeneratorWatchColumnsTest extends TestCase
{
    private static function items(string $sync = 'queue', string $level = 'row'): IndexDefinition
    {
        return IndexDefinition::builder('items')
            ->fromTable('items')
            ->field('name', 'A')
            ->filter('price', 'int')
            ->filter('published_at', 'datetime')
            ->recencyBy('published_at')
            ->sync($sync)
            ->triggerLevel($level)
            ->build();
    }

    public function testEachRelevantColumnIsListedOnceInDefinitionOrder(): void
    {
        $index = self::items();

        // published_at is both a filter and the recency column
        self::assertSame(['name', 'price', 'published_at'], (new PostgresSchemaGenerator())->relevantColumns($index, $index->effectiveWatches()[0]));
    }

    public function testAColumnUsedTwiceIsListedOnceWithoutGaps(): void
    {
        $index = IndexDefinition::builder('items')
            ->fromTable('items')
            ->field('name', 'A')
            ->filter('name_copy', 'string', 'name')
            ->filter('price', 'int')
            ->build();

        self::assertSame(['name', 'price'], (new PostgresSchemaGenerator())->relevantColumns($index, $index->effectiveWatches()[0]));
    }

    public function testTheRowTriggerComparesEveryRelevantColumnAndTheKeyOnce(): void
    {
        $generator = new PostgresSchemaGenerator();
        $sql = $generator->index(self::items()->withWatches([
            new \Fuzzphony\Core\Definition\Watch('items', columns: ['name', 'price']),
        ]))->toSql();

        self::assertStringContainsString(
            'IF TG_OP = \'UPDATE\' AND NOT (NEW."name" IS DISTINCT FROM OLD."name" OR NEW."price" IS DISTINCT FROM OLD."price" OR NEW."id" IS DISTINCT FROM OLD."id") THEN',
            $sql,
            'both named columns, then the key, which an UPDATE of the identity needs',
        );

        $sql = $generator->index(self::items()->withWatches([
            new \Fuzzphony\Core\Definition\Watch('items', columns: ['name', 'id']),
        ]))->toSql();

        self::assertStringContainsString(
            'IF TG_OP = \'UPDATE\' AND NOT (NEW."name" IS DISTINCT FROM OLD."name" OR NEW."id" IS DISTINCT FROM OLD."id") THEN',
            $sql,
            'a key that is a relevant column is compared once',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function syncModes(): iterable
    {
        yield 'queue' => ['queue', 'Queue sync on items (row level)'];
        yield 'trigger' => ['trigger', 'Trigger sync on items (row level)'];
    }

    #[DataProvider('syncModes')]
    public function testTheTriggerStatementsNameTheSyncModeInSentenceCase(string $sync, string $description): void
    {
        $descriptions = array_map(static fn(Statement $s): string => $s->description, (new PostgresSchemaGenerator())->index(self::items($sync))->statements);

        self::assertContains($description, $descriptions);
        self::assertContains(str_replace('row', 'statement', $description), array_map(static fn(Statement $s): string => $s->description, (new PostgresSchemaGenerator())->index(self::items($sync, 'statement'))->statements));
    }
}
