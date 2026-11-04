<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\TestCase;

/** The bodies of the generated sync functions: how the watched row reaches the ids query, what is queued, what a truncate wipes. */
final class SchemaGeneratorTriggerBodiesTest extends TestCase
{
    private const string TWICE = 'SELECT id FROM item WHERE a = :id';

    private static function items(string $sync, string $level): IndexDefinition
    {
        return IndexDefinition::builder('items')
            ->fromTable('item')
            ->field('name', 'A')
            ->sync($sync)
            ->triggerLevel($level)
            ->watch('detail', self::TWICE)
            ->build();
    }

    public function testAQueueTriggerQueuesTextIdsAndATriggerRefreshesTypedIds(): void
    {
        $queue = (new PostgresSchemaGenerator())->index(self::items('queue', 'row'))->toSql();
        self::assertStringContainsString("SELECT 'items', a.doc_id::text FROM (", $queue);
        self::assertStringNotContainsString('a.doc_id::bigint', $queue);

        $trigger = (new PostgresSchemaGenerator())->index(self::items('trigger', 'row'))->toSql();
        self::assertStringContainsString('(ARRAY(SELECT a.doc_id::bigint FROM (', $trigger);
        self::assertStringNotContainsString("SELECT 'items', a.doc_id::text", $trigger);
    }

    public function testATruncateOfOneSourceWipesOnlyItsQueuedIds(): void
    {
        $index = IndexDefinition::builder('site')
            ->sourceFromTable('item', 'item_t')
            ->sourceFromTable('note', 'note_t')
            ->field('title', 'A')
            ->sync('queue')
            ->triggerLevel('row')
            ->build();

        $sql = (new PostgresSchemaGenerator())->index($index)->toSql();

        self::assertStringContainsString("AND starts_with(doc_id, 'item:')", $sql);
        self::assertStringContainsString("AND starts_with(doc_id, 'note:')", $sql);
        self::assertStringNotContainsString("starts_with(doc_id, ':", $sql);
    }

    public function testTheTriggersAnIndexNoLongerNeedsAreTheOnesTheModeDoesNotCreate(): void
    {
        $generator = new PostgresSchemaGenerator();
        $statement = self::items('trigger', 'statement');
        $row = self::items('trigger', 'row');
        $manual = self::items('manual', 'row');
        $names = static fn(IndexDefinition $i): array => array_map(static fn(string $n): string => (string) preg_replace('/^.*__/', '', $n), $generator->obsoleteTriggerNames($i, $i->effectiveWatches()[0]));

        self::assertSame(['item'], $names($statement), 'the row trigger goes');
        self::assertSame(['item_ins', 'item_upd', 'item_del'], $names($row), 'the transition-table triggers go');
        self::assertSame(['item', 'item_ins', 'item_upd', 'item_del', 'item_trn'], $names($manual));
    }
}
