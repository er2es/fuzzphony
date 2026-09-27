<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    public function testObjectNames(): void
    {
        $names = new Names();
        $index = Indexes::products();
        $brand = new Watch('fz_brand');

        self::assertSame('"public"', $names->extension());
        self::assertSame('"public"', $names->quotedSchema());
        self::assertSame('fuzzphony_products', $names->sidecarName($index));
        self::assertSame('"public"."fuzzphony_products"', $names->sidecar($index));
        self::assertSame('"public"."fuzzphony_queue"', $names->queue());
        self::assertSame('fuzzphony_queue_order', $names->queueOrderIndex());
        self::assertSame('fuzzphony_meta', $names->metaName());
        self::assertSame('"public"."fuzzphony_meta"', $names->meta());
        self::assertSame('"public"."fuzzphony_norm"', $names->normFunction());
        self::assertSame('fuzzphony_refresh_products', $names->refreshFunctionName($index));
        self::assertSame('"public"."fuzzphony_refresh_products"', $names->refreshFunction($index));
        self::assertSame('fuzzphony_sync_products__fz_brand', $names->syncFunctionName($index, $brand));
        self::assertSame('fuzzphony_sync_products__shop_brand', $names->syncFunctionName($index, new Watch('shop.brand')));
        self::assertSame('"public"."fuzzphony_sync_products__fz_brand"', $names->syncFunction($index, $brand));
        self::assertSame('fuzzphony_sync_products__fz_brand', $names->triggerName($index, $brand));
        self::assertSame('fuzzphony_sync_products__fz_brand_ins', $names->triggerName($index, $brand, '_ins'));
        self::assertSame('fuzzphony_products_tsv', $names->indexName($index, 'tsv'));
    }

    public function testTextSearchNames(): void
    {
        $names = new Names();

        self::assertSame('fuzzphony_german', $names->textConfigName(new TextConfig('german')));
        self::assertSame('german', $names->textConfigName(new TextConfig('german', unaccent: false)));
        self::assertSame('"public"."fuzzphony_german"', $names->textConfig(new TextConfig('german')));
        self::assertSame('"german"', $names->textConfig(new TextConfig('german', unaccent: false)));
        self::assertSame("'\"public\".\"fuzzphony_english\"'::regconfig", $names->regconfig(new TextConfig()));
        self::assertSame("'\"simple\"'::regconfig", $names->regconfig(new TextConfig('simple', unaccent: false)));
        self::assertSame('fuzzphony_german_stop', $names->stopDictionaryName(new TextConfig('german')));
        self::assertSame('"public"."fuzzphony_german_stop"', $names->stopDictionary(new TextConfig('german')));
    }

    public function testLongNamesAreCutToThePostgresLimitAndStayUnique(): void
    {
        $long = str_repeat('a', 70);

        self::assertSame('short', Names::limit('short'));
        self::assertSame(str_repeat('a', 63), Names::limit(str_repeat('a', 63)), 'exactly at the limit: unchanged');
        self::assertSame(substr($long, 0, 54) . '_' . hash('crc32b', $long), Names::limit($long));
        self::assertSame(63, strlen(Names::limit($long)));
        self::assertSame(substr($long, 0, 11) . '_' . hash('crc32b', $long), Names::limit($long, 20));
        $mixed = implode('', range('a', 'z')) . str_repeat('0123456789', 5);
        self::assertSame('abcdefghijklmnopqrstuvwxyz0123456789012345678901234567_' . hash('crc32b', $mixed), Names::limit($mixed), 'the kept part is the start of the name');

        $index = IndexDefinition::builder(str_repeat('long_index_name_', 3))->fromTable('t')->field('name')->build();
        self::assertLessThanOrEqual(63, strlen((new Names())->triggerName($index, new Watch(str_repeat('watched_table_', 4)), '_trn')));
    }

    public function testAnInvalidExtensionSchemaIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Invalid extension schema "not a valid ident; drop table".');

        new Names('not a valid ident; drop table');
    }

    public function testEveryOwnObjectIsQualifiedWithTheConfiguredSchema(): void
    {
        $names = new Names(schema: 'Fuzzphony');
        $index = Indexes::products();

        self::assertSame('Fuzzphony', $names->schema);
        self::assertSame('"Fuzzphony"', $names->quotedSchema());
        self::assertSame('"Fuzzphony"."fuzzphony_products"', $names->sidecar($index));
        self::assertSame('"Fuzzphony"."fuzzphony_queue"', $names->queue());
        self::assertSame('"public"', $names->extension(), 'the extensions stay where they are');
        self::assertSame('fuzzphony_products_tsv', $names->indexName($index, 'tsv'), 'an index lives in its table\'s schema');
        self::assertSame('fuzzphony_sync_products__fz_brand_trn', $names->triggerName($index, new Watch('fz_brand'), '_trn'), 'a trigger lives on its table');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSchemas(): iterable
    {
        yield 'space' => ['bad name', 'Invalid schema "bad name": use a plain identifier such as "fuzzphony".'];
        yield 'dot' => ['a.b', 'Invalid schema "a.b": use a plain identifier such as "fuzzphony".'];
        yield 'empty' => ['', 'Invalid schema "": use a plain identifier such as "fuzzphony".'];
        yield 'reserved' => ['pg_search', 'Invalid schema "pg_search": names starting with "pg_" are reserved by PostgreSQL.'];
    }

    #[DataProvider('invalidSchemas')]
    public function testAnInvalidSchemaIsAConfigurationError(string $schema, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        new Names(schema: $schema);
    }
}
