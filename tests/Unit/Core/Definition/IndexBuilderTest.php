<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexBuilderTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexBuilder): IndexBuilder, string}> */
    public static function typos(): iterable
    {
        yield 'weight' => [static fn(IndexBuilder $b): IndexBuilder => $b->field('name', 'e'), 'Unknown weight "E" of field "name". Allowed: A, B, C, D.'];
        yield 'filter type' => [static fn(IndexBuilder $b): IndexBuilder => $b->filter('price', 'integer'), 'Unknown filter type "integer" of filter "price". Allowed: bool, int, float, string, date, datetime.'];
        yield 'id type' => [static fn(IndexBuilder $b): IndexBuilder => $b->idType('bigint'), 'Unknown id type "bigint". Allowed: int, uuid, string.'];
        yield 'sync mode' => [static fn(IndexBuilder $b): IndexBuilder => $b->sync('realtime'), 'Unknown sync mode "realtime". Allowed: orm, trigger, queue, manual.'];
        yield 'trigger level' => [static fn(IndexBuilder $b): IndexBuilder => $b->triggerLevel('each'), 'Unknown trigger level "each". Allowed: statement, row.'];
    }

    /** @param \Closure(IndexBuilder): IndexBuilder $typo */
    #[DataProvider('typos')]
    public function testAnEnumTypoNamesTheIndexAndTheAllowedValues(\Closure $typo, string $message): void
    {
        try {
            $typo(IndexDefinition::builder('products'));
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertSame('products', $e->index);
            self::assertSame([$message], $e->violations);
        }
    }

    public function testValidStringsAndCasesAreAccepted(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name', 'a')
            ->filter('price', 'int')
            ->idType('uuid')
            ->sync('manual')
            ->triggerLevel('row')
            ->build();

        self::assertSame('A', $definition->fields[0]->weight->value);
        self::assertSame('int', $definition->filters[0]->type->value);
        self::assertSame('uuid', $definition->idType->value);
        self::assertSame('manual', $definition->sync->value);
        self::assertSame('row', $definition->triggerLevel->value);
    }

    public function testSynonymsCanBeReadFromAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'syn');
        self::assertNotFalse($path);
        file_put_contents($path, "tv, television
");

        try {
            $definition = IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')->synonymsFile($path)->build();
        } finally {
            unlink($path);
        }

        self::assertSame([['tv', 'television']], $definition->synonyms->toEntries());
    }

    public function testSynonymsAreSetAndValidatedAtBuild(): void
    {
        $builder = IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')->synonyms([['tv', 'television']]);

        self::assertSame([['tv', 'television']], $builder->build()->synonyms->toEntries());
        self::assertTrue(IndexDefinition::builder('p')->fromTable('p')->field('name', 'A')->build()->synonyms->isEmpty());

        $this->expectException(InvalidDefinition::class);
        $builder->synonyms([['tv']])->build();
    }
}
