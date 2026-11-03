<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexDefinitionClass;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Tests\Fixtures\CustomersManyIndex;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManyValuesDefinitionTest extends TestCase
{
    private const array CITY = ['from' => 'address', 'key' => 'customer_id', 'column' => 'city', 'limit' => 20];

    private static function byBuilder(): IndexDefinition
    {
        return IndexDefinition::builder('customers')->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', many: new ManyValues('address', 'customer_id', 'city', 20))
            ->filter('city', 'string', many: self::CITY)
            ->build();
    }

    /** @return array<string, mixed> */
    private static function yaml(): array
    {
        return [
            'source' => ['table' => 'customer'],
            'fields' => ['name' => ['weight' => 'A', 'fuzzy' => true], 'city' => ['weight' => 'C', 'many' => self::CITY]],
            'filters' => ['city' => ['type' => 'string', 'many' => self::CITY]],
        ];
    }

    public function testBuilderYamlAndDefinitionClassAreTheSameDefinition(): void
    {
        $builder = self::byBuilder();
        $yaml = (new ArrayDefinitionLoader())->load('customers', self::yaml());
        $class = IndexDefinitionClass::fromClass(CustomersManyIndex::class);

        self::assertEquals($builder, $yaml);
        self::assertEquals($builder, $class);
        self::assertSame(Fingerprint::definition($builder), Fingerprint::definition($yaml));
        self::assertSame(Fingerprint::documents($builder), Fingerprint::documents($class));
    }

    public function testTheFingerprintCoversTheManyDeclaration(): void
    {
        $base = self::byBuilder();
        $limit = IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A', fuzzy: true)
            ->field('city', 'C', many: new ManyValues('address', 'customer_id', 'city', 21))->filter('city', 'string', many: self::CITY)->build();
        $plain = IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A', fuzzy: true)->field('city', 'C')->filter('city', 'string')->build();

        self::assertNotSame(Fingerprint::documents($base), Fingerprint::documents($limit));
        self::assertNotSame(Fingerprint::definition($base), Fingerprint::definition($plain));
        self::assertNotSame(Fingerprint::documents($base), Fingerprint::documents($plain));
    }

    public function testTheChildTableIsWatchedByDerivation(): void
    {
        $index = self::byBuilder();
        $watches = $index->effectiveWatches();

        self::assertSame(['customer', 'address'], array_map(static fn($w): string => $w->table, $watches));
        self::assertSame('SELECT :id', $watches[1]->affectedIds);
        self::assertSame('customer_id', $watches[1]->keyColumn);
        self::assertSame(['city'], $watches[1]->columns);
        self::assertSame([], $index->watches, 'nothing is added to the declared watches');
    }

    public function testAnExplicitWatchOfTheChildTableWins(): void
    {
        $index = IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A')
            ->field('city', 'C', many: self::CITY)->watch('address', 'SELECT customer_id FROM address WHERE id = :id')->build();

        self::assertCount(2, $index->effectiveWatches());
        self::assertSame('SELECT customer_id FROM address WHERE id = :id', $index->effectiveWatches()[1]->affectedIds);
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function invalid(): iterable
    {
        $base = static fn(): IndexBuilder => IndexDefinition::builder('customers')->fromTable('customer')->field('name', 'A');
        yield 'unknown key with a hint' => [static fn() => $base()->field('city', 'C', many: ['from' => 'a', 'key' => 'k', 'column' => 'c', 'limt' => 5]), 'Did you mean "limit"'];
        yield 'missing from' => [static fn() => $base()->field('city', 'C', many: ['key' => 'k', 'column' => 'c']), 'needs "from"'];
        yield 'limit not an integer' => [static fn() => $base()->field('city', 'C', many: ['from' => 'a', 'key' => 'k', 'column' => 'c', 'limit' => '5']), 'integer'];
        yield 'limit zero' => [static fn() => $base()->field('city', 'C', many: new ManyValues('a', 'k', 'c', 0)), 'between 1 and 10000'];
        yield 'bad table' => [static fn() => $base()->field('city', 'C', many: new ManyValues('a b', 'k', 'c')), 'not a valid identifier'];
        yield 'bad column' => [static fn() => $base()->field('city', 'C', many: new ManyValues('a', 'k;', 'c')), 'not a valid column name'];
        yield 'with a column' => [static fn() => $base()->field('city', 'C', column: 'x', many: self::CITY), 'exclusive'];
        yield 'code field' => [static fn() => $base()->field('city', 'C', code: true, many: self::CITY), '"code"'];
        yield 'primary field' => [static fn() => IndexDefinition::builder('customers')->fromTable('customer')->field('city', 'A', many: self::CITY), 'primary field'];
        yield 'int filter' => [static fn() => $base()->filter('n', 'int', many: self::CITY), 'only string filters'];
        yield 'tenant filter' => [static fn() => $base()->filter('t', 'string', many: self::CITY)->tenant('t'), 'tenant filter'];
        yield 'two keys for one table' => [static fn() => $base()->field('city', 'C', many: self::CITY)->field('street', 'C', many: ['from' => 'address', 'key' => 'other_id', 'column' => 'street']), 'different key columns'];
        yield 'multi source' => [static fn() => IndexDefinition::builder('site')->source('a', 'SELECT 1 AS id', watch: ['t' => 'SELECT :id'])->source('b', 'SELECT 1 AS id', watch: ['u' => 'SELECT :id'])->field('t', 'A')->field('city', 'C', many: self::CITY), 'multi-source'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('invalid')]
    public function testAnInvalidDeclarationIsRefusedWithAFix(\Closure $make, string $expected): void
    {
        try {
            $result = $make();
            if ($result instanceof IndexBuilder) {
                $result->build();
            }
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidYaml(): iterable
    {
        yield 'many is not a map' => [['fields' => ['name' => 'A', 'city' => ['weight' => 'C', 'many' => 'address']]], '"many" must be a map'];
        yield 'unknown key' => [['fields' => ['name' => 'A', 'city' => ['weight' => 'C', 'many' => ['from' => 'a', 'key' => 'k', 'column' => 'c', 'colum' => 'x']]]], 'Did you mean "column"'];
        yield 'missing key' => [['fields' => ['name' => 'A', 'city' => ['weight' => 'C', 'many' => ['from' => 'a', 'column' => 'c']]]], 'needs "key"'];
        yield 'limit a string' => [['fields' => ['name' => 'A'], 'filters' => ['c' => ['type' => 'string', 'many' => ['from' => 'a', 'key' => 'k', 'column' => 'c', 'limit' => 'x']]]], '"limit" must be an integer'];
        yield 'id empty' => [['fields' => ['name' => 'A'], 'filters' => ['c' => ['type' => 'string', 'many' => ['from' => 'a', 'key' => 'k', 'column' => 'c', 'id' => '']]]], 'primary key column of the child table'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidYaml')]
    public function testYamlProblemsAreReported(array $config, string $expected): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage($expected);
        (new ArrayDefinitionLoader())->load('customers', $config);
    }

    public function testExportersWriteTheDeclaration(): void
    {
        $index = self::byBuilder();

        $array = (new ArrayExporter())->export($index);
        self::assertSame(self::CITY, $array['fields']['city']['many'] ?? null);
        self::assertSame(['type' => 'string', 'many' => self::CITY], $array['filters']['city'] ?? null);
        self::assertEquals($index, (new ArrayDefinitionLoader())->load('customers', $array));
        self::assertStringContainsString("many: ['from' => 'address', 'key' => 'customer_id', 'column' => 'city', 'limit' => 20]", (new BuilderExporter())->export($index));
        self::assertFalse((new AttributeExporter())->supports($index), 'attributes cannot say it');
        $this->expectException(InvalidArgument::class);
        (new AttributeExporter())->export($index);
    }

    public function testTheValuesTableAndTheFilterColumnExistOnlyForAnIndexThatDeclaresMany(): void
    {
        $generator = new PostgresSchemaGenerator(new Names());
        $many = self::byBuilder();
        $plain = Indexes::products();

        self::assertSame('text[]', $generator->columns($many)['f_city']);
        self::assertSame('USING gin ("f_city")', $generator->indexes($many)['fuzzphony_customers__f_city']);
        $sql = implode("\n", array_map(static fn($s): string => $s->sql, $generator->index($many)->statements));
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "public"."fuzzphony_customers__values"', $sql);
        self::assertStringContainsString('PRIMARY KEY (doc, field, ord)', $sql);
        $drop = implode("\n", array_map(static fn($s): string => $s->sql, $generator->drop($many)->statements));
        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_customers__values"', $drop);

        foreach ([$generator->index($plain), $generator->drop($plain)] as $plan) {
            foreach ($plan->statements as $statement) {
                self::assertStringNotContainsString('__values', $statement->sql);
            }
        }
        self::assertStringNotContainsString('__values', $generator->swap($plain));
        self::assertStringContainsString('__values', $generator->swap($many));
    }
}
