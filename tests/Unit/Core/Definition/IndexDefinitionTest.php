<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    public function testWithCanSwitchTheEntityClassToAnExistingOne(): void
    {
        $definition = Indexes::products()->with(entityClass: Product::class);

        self::assertSame(Product::class, $definition->entityClass);
    }

    public function testWithIgnoresAFieldsListContainingSomethingOtherThanFieldDefinitions(): void
    {
        $original = Indexes::products();

        $changed = $original->with(fields: ['not a field definition']);

        self::assertSame($original->fields, $changed->fields, 'invalid override is ignored, original fields are kept');
    }

    public function testWithIgnoresAProfilesMapWithANonStringKey(): void
    {
        $original = Indexes::products();

        $changed = $original->with(profiles: [0 => new RankingProfile()]);

        self::assertSame($original->profiles, $changed->profiles, 'invalid override is ignored, original profiles are kept');
    }

    public function testWithIgnoresAProfilesMapContainingSomethingOtherThanARankingProfile(): void
    {
        $original = Indexes::products();

        $changed = $original->with(profiles: ['default' => 'not a profile']);

        self::assertSame($original->profiles, $changed->profiles, 'invalid override is ignored, original profiles are kept');
    }
}
