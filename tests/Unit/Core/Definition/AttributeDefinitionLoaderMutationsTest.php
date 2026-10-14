<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use PHPUnit\Framework\TestCase;

final class AttributeDefinitionLoaderMutationsTest extends TestCase
{
    public function testAnExplicitFilterColumnWinsOverTheNamingStrategy(): void
    {
        $class = new #[Searchable(name: 'things', table: 'thing')] class {
            #[SearchField]
            public string $title = '';

            #[SearchFilter(column: 'price_cents')]
            public int $price = 0;
        };

        $definition = (new AttributeDefinitionLoader())->load($class::class);

        self::assertSame('price_cents', $definition->filter('price')->column());
    }

    public function testASynonymsFileAloneIsEnough(): void
    {
        $class = new #[Searchable(name: 'things', table: 'thing', synonymsFile: __DIR__ . '/synonyms-fixture.txt')] class {
            #[SearchField]
            public string $title = '';
        };

        $definition = (new AttributeDefinitionLoader())->load($class::class);

        self::assertSame([['tv', 'television']], $definition->synonyms->toEntries());
    }
}
