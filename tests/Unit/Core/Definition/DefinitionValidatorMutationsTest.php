<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use PHPUnit\Framework\TestCase;

final class DefinitionValidatorMutationsTest extends TestCase
{
    public function testTheTenantMessageSaysThereAreNoFiltersWhenNoneIsDeclared(): void
    {
        $definition = new IndexDefinition(name: 'products', source: Source::table('product'), fields: [new FieldDefinition('name')], tenant: 'account_id');

        self::assertSame(['tenant("account_id") must reference a declared filter. Known filters: (none).'], DefinitionValidator::validate($definition));
    }

    public function testTheTenantMessageListsTheDeclaredFilters(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            filters: [new FilterDefinition('brand', FilterType::String), new FilterDefinition('price', FilterType::Int)],
            tenant: 'account_id',
        );

        self::assertSame(['tenant("account_id") must reference a declared filter. Known filters: brand, price.'], DefinitionValidator::validate($definition));
    }
}
