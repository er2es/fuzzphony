<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Engine\Postgres\SameRow;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A many filter's `same_row_as`: the definition (every way to write it, the messages), the fingerprints and the schema it adds. */
final class SameRowAsTest extends TestCase
{
    private const array ADDRESS = ['from' => 'address', 'key' => 'customer_id', 'column' => 'line', 'rank' => 'best'];
    private const array CITY = ['from' => 'address', 'key' => 'customer_id', 'column' => 'city'];

    /** @param array<string, string>|null $city */
    private static function builder(?array $city = null, string $name = 'customers'): IndexBuilder
    {
        return IndexDefinition::builder($name)->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('address', 'C', fuzzy: true, many: self::ADDRESS)
            ->filter('city', 'string', many: $city ?? [...self::CITY, 'same_row_as' => 'address']);
    }

    public function testTheOptionIsReadWrittenAndExportedTheSameWayEverywhere(): void
    {
        $builder = self::builder()->build();
        $yaml = (new ArrayDefinitionLoader())->load('customers', [
            'source' => ['table' => 'customer'],
            'fields' => ['name' => ['weight' => 'A', 'fuzzy' => true], 'address' => ['weight' => 'C', 'fuzzy' => true, 'many' => self::ADDRESS]],
            'filters' => ['city' => ['type' => 'string', 'many' => [...self::CITY, 'same_row_as' => 'address']]],
        ]);

        self::assertEquals($builder, $yaml);
        self::assertSame('address', $builder->filter('city')->many?->sameRowAs);
        $array = (new ArrayExporter())->export($builder);
        self::assertSame(['city' => ['type' => 'string', 'many' => [...self::CITY, 'same_row_as' => 'address']]], $array['filters'] ?? null);
        self::assertEquals($builder, (new ArrayDefinitionLoader())->load('customers', $array));
        self::assertStringContainsString("'same_row_as' => 'address'", (new BuilderExporter())->export($builder));
        self::assertStringNotContainsString('same_row_as', (new BuilderExporter())->export(self::builder(self::CITY)->build()));
        self::assertEquals(new ManyValues('address', 'customer_id', 'city', sameRowAs: 'address'), ManyValues::fromArray('i', 'x', [...self::CITY, 'same_row_as' => 'address']));
    }

    public function testBothFingerprintsSeeTheOptionAndNoOtherHashChanges(): void
    {
        $without = self::builder(self::CITY)->build();
        $with = self::builder()->build();

        self::assertNotSame(Fingerprint::definition($without), Fingerprint::definition($with), 'schema --apply adds the column');
        self::assertNotSame(Fingerprint::documents($without), Fingerprint::documents($with), 'a reindex fills the column');
        self::assertSame(['address', 'customer_id', 'city', 50, 'id'], (new ManyValues('address', 'customer_id', 'city'))->fingerprint(), 'the 0.12.4 hash');
        self::assertSame(['address', 'customer_id', 'city', 50, 'id', 'same_row_as:address'], (new ManyValues('address', 'customer_id', 'city', sameRowAs: 'address'))->documentsFingerprint());
        self::assertSame(self::CITY, (new ManyValues('address', 'customer_id', 'city'))->toArray());
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function invalid(): iterable
    {
        $base = static fn(): IndexBuilder => IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A', fuzzy: true)->field('address', 'C', many: self::ADDRESS);
        yield 'unknown field' => [static fn() => $base()->filter('city', 'string', many: [...self::CITY, 'same_row_as' => 'addres']), 'Filter "city": many "same_row_as" names "addres", which is not a field of the index. Did you mean "address"?'];
        yield 'single-valued field' => [static fn() => $base()->filter('city', 'string', many: [...self::CITY, 'same_row_as' => 'name']), 'Filter "city": many "same_row_as" must name a field with "many" (several values), "name" has one value per document.'];
        yield 'rank: whole field' => [static fn() => IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')->field('address', 'C', many: ['from' => 'address', 'key' => 'customer_id', 'column' => 'line'])->filter('city', 'string', many: [...self::CITY, 'same_row_as' => 'address']), 'Filter "city": many "same_row_as" must name a field with many "rank: best" (only those take part in manyMatch(\'single\'), where the filter holds for the same row), "address" is "rank: whole".'];
        yield 'other child rows' => [static fn() => $base()->filter('city', 'string', many: ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'city', 'same_row_as' => 'address']), 'Filter "city": many "same_row_as": field "address" reads address/customer_id/id, this filter reads erp_address/customer_id/id; a value row and a filter value are on the same row only when from, key and id are equal.'];
        yield 'other id' => [static fn() => $base()->filter('city', 'string', many: [...self::CITY, 'id' => 'pk', 'same_row_as' => 'address']), 'this filter reads address/customer_id/pk'];
        yield 'on a field' => [static fn() => IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')->field('address', 'C', many: [...self::ADDRESS, 'same_row_as' => 'name']), 'Field "address": many "same_row_as" is an option of a many filter, not of a field.'];
        yield 'not a string' => [static fn() => $base()->filter('city', 'string', many: [...self::CITY, 'same_row_as' => 5]), 'Filter "city": many "same_row_as" must name a many field of the index (a non-empty string)'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('invalid')]
    public function testAWrongDeclarationIsRefusedWithAFix(\Closure $make, string $expected): void
    {
        try {
            $result = $make();
            if ($result instanceof IndexBuilder) {
                $result->build();
            }
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertStringContainsString('"customers"', $e->getMessage());
        }
    }

    public function testTheLoaderReportsAValueThatIsNotAString(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Filter "city": many "same_row_as" must name a many field of the index (a non-empty string)');
        (new ArrayDefinitionLoader())->load('customers', [
            'source' => ['table' => 'customer'],
            'fields' => ['name' => 'A', 'address' => ['weight' => 'C', 'many' => self::ADDRESS]],
            'filters' => ['city' => ['type' => 'string', 'many' => [...self::CITY, 'same_row_as' => ['address']]]],
        ]);
    }

    public function testTheColumnNameHashesWhereTheValueComesFrom(): void
    {
        $names = new Names();
        $name = $names->sameRowColumnName(self::builder()->build()->filter('city'));

        self::assertMatchesRegularExpression('/^fr_city_[0-9a-f]{8}$/', $name);
        self::assertNotSame($name, $names->sameRowColumnName(self::builder([...self::CITY, 'column' => 'zip', 'same_row_as' => 'address'])->build()->filter('city')), 'another column: a new, empty column');
        $long = $names->sameRowColumnName(self::builder()->build()->filter('city'));
        self::assertSame($name, $long, 'stable');
        $tooLong = IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')->field('address', 'C', many: self::ADDRESS)
            ->filter(str_repeat('c', 48), 'string', many: [...self::CITY, 'same_row_as' => 'address'])->build();
        self::assertSame(60, strlen($names->sameRowColumnName($tooLong->filter(str_repeat('c', 48)))), 'the longest filter name fits PostgreSQL\'s 63 bytes');
        // two filters over the same source whose names agree in the part that fits (longer than a valid name, 48: the name is not
        // relied on to be short): the hash covers the name too, so they never share a column
        $many = new ManyValues('address', 'customer_id', 'city', sameRowAs: 'address');
        $a = $names->sameRowColumnName(new FilterDefinition(str_repeat('c', 55) . 'a', FilterType::String, many: $many));
        $b = $names->sameRowColumnName(new FilterDefinition(str_repeat('c', 55) . 'b', FilterType::String, many: $many));
        self::assertNotSame($a, $b, 'one column each, never the same column twice in the INSERT');
        self::assertStringStartsWith('fr_' . str_repeat('c', 51) . '_', $a, 'the readable prefix stays');
        self::assertSame(63, strlen($a));
        $this->expectException(\LogicException::class);
        $names->sameRowColumnName(IndexDefinition::builder('x')->fromTable('t')->field('name', 'A')->filter('k', 'string')->build()->filter('k'));
    }

    public function testThePlanAddsTheNullableColumnAndTheRefreshWritesItFromTheSameChildRow(): void
    {
        $generator = new PostgresSchemaGenerator(new Names());
        $with = self::builder()->build();
        $column = (new Names())->sameRowColumnName($with->filter('city'));
        $sql = implode("\n", array_map(static fn($s): string => $s->sql, $generator->index($with)->statements));

        self::assertStringContainsString(sprintf('ALTER TABLE "public"."fuzzphony_customers__values" ADD COLUMN "%s" text[];', $column), $sql, 'nullable, no default: metadata only');
        self::assertStringContainsString(sprintf('INSERT INTO "public"."fuzzphony_customers__values" (doc, field, ord, v, t, z, "%s")', $column), $sql);
        self::assertStringContainsString(', c."city"::text AS fr0', $sql);
        self::assertStringContainsString("CASE WHEN m.fr0 IS NULL THEN '{}'::text[] ELSE ARRAY[m.fr0] END", $sql);
        self::assertStringContainsString(sprintf("attname ~ '^fr_' AND NOT attisdropped AND attname <> ALL (ARRAY['', '%s']::text[])", $column), $sql);

        $without = implode("\n", array_map(static fn($s): string => $s->sql, $generator->index(self::builder(self::CITY)->build())->statements));
        self::assertStringContainsString('INSERT INTO "public"."fuzzphony_customers__values" (doc, field, ord, v, t, z)', $without, 'the 0.12.4 column list');
        self::assertStringNotContainsString(' AS fr0', $without);
        self::assertStringContainsString("attname ~ '^fr_' AND NOT attisdropped AND attname <> ALL (ARRAY['']::text[])", $without, 'a removed option drops its column');
    }

    public function testOnlyAFieldTheViewerChecksInOneValueCouplesItsFilters(): void
    {
        $index = self::builder()->field('contact', 'B', many: ['from' => 'contact', 'key' => 'customer_id', 'column' => 'name', 'rank' => 'best'])
            ->filter('zip', 'string', many: [...self::CITY, 'column' => 'zip', 'same_row_as' => 'address'])
            ->filter('kind', 'string')->build();

        self::assertSame(['city', 'zip'], array_map(static fn($f): string => $f->name, SameRow::filters($index)));
        self::assertSame(['city' => 'address', 'zip' => 'address'], SameRow::coupled($index, ['address', 'contact']));
        self::assertSame([], SameRow::coupled($index, ['contact']), 'address hidden or not named: no coupling');
        self::assertSame([], SameRow::coupled($index, []));
        self::assertSame(['city', 'zip'], array_map(static fn($f): string => $f->name, SameRow::ofField($index, 'address')));
        self::assertSame([], SameRow::ofField($index, 'contact'));
    }
}
