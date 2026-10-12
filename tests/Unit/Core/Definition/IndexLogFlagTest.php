<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexLogFlagTest extends TestCase
{
    public function testAnIndexIsLoggedByDefault(): void
    {
        self::assertTrue(Indexes::products()->log);
        self::assertTrue(IndexDefinition::builder('things')->field('name', 'A')->build()->log);
    }

    public function testTheBuilderTurnsItOff(): void
    {
        $definition = IndexDefinition::builder('things')->field('name', 'A')->log(false)->build();

        self::assertFalse($definition->log);
        self::assertTrue($definition->withLog(true)->log);
        self::assertFalse(Indexes::products()->withLog(false)->log);
    }

    public function testTheWitherKeepsEverythingElse(): void
    {
        $original = Indexes::products(tenant: true);
        $copy = $original->withLog(false);

        self::assertSame($original->tenant, $copy->tenant);
        self::assertSame($original->name, $copy->name);
        self::assertSame($original->synonyms, $copy->synonyms);
    }

    public function testTheYamlKeyIsAccepted(): void
    {
        $loader = new ArrayDefinitionLoader();

        self::assertFalse($loader->load('things', ['fields' => ['name' => 'A'], 'log' => false])->log);
        self::assertTrue($loader->load('things', ['fields' => ['name' => 'A'], 'log' => true])->log);
        self::assertTrue($loader->load('things', ['fields' => ['name' => 'A']])->log);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notBooleans(): iterable
    {
        yield 'the string false' => ['false'];
        yield 'the string true' => ['true'];
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'an empty string' => [''];
        yield 'a list' => [[true]];
    }

    #[DataProvider('notBooleans')]
    public function testTheYamlKeyMustBeARealBoolean(mixed $value): void
    {
        $loader = new ArrayDefinitionLoader();

        foreach ([
            static fn() => $loader->load('things', ['fields' => ['name' => 'A'], 'log' => $value]),
            static fn() => $loader->override(Indexes::products(), ['log' => $value]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an InvalidDefinition');
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString('"log" must be true or false', $e->getMessage());
            }
        }
    }

    public function testTheYamlKeyOverridesAnAttributeDefinition(): void
    {
        $loader = new ArrayDefinitionLoader();

        self::assertFalse($loader->override(Indexes::products(), ['log' => false])->log);
        self::assertTrue($loader->override(Indexes::products()->withLog(false), ['log' => true])->log);
        self::assertFalse($loader->override(Indexes::products()->withLog(false), [])->log);
    }

    public function testTheAttributeTurnsItOff(): void
    {
        $off = new #[Searchable(name: 'things', table: 'thing', log: false)] class {
            #[SearchField]
            public string $title = '';
        };
        $on = new #[Searchable(name: 'things', table: 'thing')] class {
            #[SearchField]
            public string $title = '';
        };
        $loader = new AttributeDefinitionLoader();

        self::assertFalse($loader->load($off::class)->log);
        self::assertTrue($loader->load($on::class)->log);
    }
}
