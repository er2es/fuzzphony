<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class GrantedFieldTest extends TestCase
{
    public function testTheBuilderCarriesTheGrant(): void
    {
        $definition = IndexDefinition::builder('people')->field('name', 'A')->field('salary', 'B', granted: 'ROLE_HR')->build();

        self::assertNull($definition->fields[0]->granted);
        self::assertSame('ROLE_HR', $definition->fields[1]->granted);
        self::assertSame('ROLE_HR', $definition->field('salary')?->granted);
    }

    public function testAFieldIsOpenByDefault(): void
    {
        self::assertNull((new FieldDefinition('name'))->granted);
        foreach (Indexes::products()->fields as $field) {
            self::assertNull($field->granted);
        }
    }

    public function testTheAttributeReachesTheDefinition(): void
    {
        $entity = new #[Searchable(name: 'people', table: 'person')] class {
            #[SearchField]
            public string $name = '';

            #[SearchField('B', granted: 'ROLE_HR')]
            public string $salary = '';
        };

        $definition = (new AttributeDefinitionLoader())->load($entity::class);

        self::assertNull($definition->field('name')?->granted);
        self::assertSame('ROLE_HR', $definition->field('salary')?->granted);
    }

    public function testTheYamlFieldKeyReachesTheDefinition(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('people', ['fields' => [
            'name' => 'A',
            'salary' => ['weight' => 'B', 'granted' => 'ROLE_HR'],
        ]]);

        self::assertNull($definition->field('name')?->granted);
        self::assertSame('ROLE_HR', $definition->field('salary')?->granted);
    }

    public function testFieldNamesListTheNamesInOrder(): void
    {
        self::assertSame(['name', 'brand', 'description'], Indexes::products()->fieldNames());
        self::assertSame(['b', 'a'], IndexDefinition::builder('x')->field('b', 'A')->field('a', 'B')->build()->fieldNames());
    }
}
