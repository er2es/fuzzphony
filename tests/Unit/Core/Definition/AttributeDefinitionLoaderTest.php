<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Product;
use Fuzzphony\Tests\Fixtures\TenantScopedProduct;
use Fuzzphony\Tests\Fixtures\UninferableFilterProduct;
use PHPUnit\Framework\TestCase;

final class AttributeDefinitionLoaderTest extends TestCase
{
    public function testConventionsFillInEverything(): void
    {
        $definition = (new AttributeDefinitionLoader())->load(Product::class);

        self::assertSame('products', $definition->name);
        self::assertSame('product', $definition->source->table);
        self::assertSame(IdType::Int, $definition->idType);
        self::assertSame(SyncMode::Trigger, $definition->sync);
        self::assertSame('fuzzphony_hungarian', $definition->text->configName());
        self::assertSame(Product::class, $definition->entityClass);

        self::assertSame(Weight::A, $definition->field('name')?->weight);
        self::assertTrue($definition->field('name')->fuzzy);
        self::assertFalse($definition->field('description')?->highlight);

        self::assertSame(FilterType::Int, $definition->filter('price')->type);
        self::assertSame(FilterType::Bool, $definition->filter('in_stock')->type);
        self::assertSame('in_stock', $definition->filter('in_stock')->column());
        self::assertSame(FilterType::DateTime, $definition->filter('published_at')->type);
        self::assertSame('publishedAt', $definition->recencyColumn, 'explicit column names are kept as written');
    }

    public function testRegistryResolvesByClassAndName(): void
    {
        $registry = new IndexRegistry([(new AttributeDefinitionLoader())->load(Product::class)]);

        self::assertSame($registry->get('products'), $registry->get(Product::class));
    }

    public function testUnknownFilterSuggestsTheClosestName(): void
    {
        $definition = (new AttributeDefinitionLoader())->load(Product::class);

        $this->expectExceptionMessage('Index "products" has no filter "prise". Did you mean "price"?');
        $definition->filter('prise');
    }

    public function testTenantAttributeSetsTheTenantScope(): void
    {
        $definition = (new AttributeDefinitionLoader())->load(TenantScopedProduct::class);

        self::assertSame('account_id', $definition->tenant);
    }

    public function testClassesWithoutTheSearchableAttributeAreRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Class stdClass has no #[Searchable] attribute.');
        (new AttributeDefinitionLoader())->load(\stdClass::class);
    }

    public function testAnUntypedFilterPropertyWithNoExplicitTypeIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Cannot infer the filter type of UninferableFilterProduct::$tags; pass it explicitly: #[SearchFilter(type: "int")].');
        (new AttributeDefinitionLoader())->load(UninferableFilterProduct::class);
    }
}
