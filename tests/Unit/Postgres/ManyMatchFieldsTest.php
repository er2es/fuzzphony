<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\ManyMatch;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Engine\Postgres\ManyMatchFields;
use PHPUnit\Framework\TestCase;

final class ManyMatchFieldsTest extends TestCase
{
    private static function index(): IndexDefinition
    {
        $many = static fn(string $column, string $rank): array => ['from' => 'address', 'key' => 'customer_id', 'column' => $column, 'rank' => $rank];

        return IndexDefinition::builder('customers')->fromTable('customer')
            ->field('name', 'A')
            ->field('city', 'C', many: $many('city', 'best'))
            ->field('street', 'C', many: $many('street', 'best'))
            ->field('note', 'D', many: ['from' => 'address', 'key' => 'customer_id', 'column' => 'note'])
            ->build();
    }

    /**
     * @param list<string>      $named
     * @param list<string>|null $allowed
     */
    private static function query(ManyMatch $mode, array $named = [], ?array $allowed = null): SearchQuery
    {
        return new SearchQuery(allowedFields: $allowed, manyMatch: $mode, manyMatchFields: $named);
    }

    public function testAnyAppliesToNothing(): void
    {
        self::assertSame([], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Any)));
    }

    public function testSingleWithoutNamesAppliesToEveryVisibleBestField(): void
    {
        self::assertSame(['city', 'street'], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single)));
        self::assertSame(['city'], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, [], ['name', 'city', 'note'])));
    }

    public function testAViewerWhoSeesNoBestFieldGetsASilentNoOp(): void
    {
        self::assertSame([], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, [], ['name'])));
        self::assertSame([], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, [], [])));
    }

    public function testNamedFieldsAreKept(): void
    {
        self::assertSame(['street'], ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, ['street'])));
    }

    public function testANameThatIsNotAVisibleBestFieldIsRefusedListingOnlyVisibleOnes(): void
    {
        foreach (['note' => 'city, street', 'name' => 'city, street', 'zzz' => 'city, street'] as $name => $listed) {
            try {
                ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, [$name]));
                self::fail('expected InvalidQuery');
            } catch (InvalidQuery $e) {
                self::assertSame(sprintf('manyMatch(): field "%s" is not a many field with rank: best. Fields with it: %s.', $name, $listed), $e->getMessage());
            }
        }
        // a field the viewer may not see reads exactly like an unknown one and does not name itself in the list
        try {
            ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, ['street'], ['name', 'city']));
            self::fail('expected InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame('manyMatch(): field "street" is not a many field with rank: best. Fields with it: city.', $e->getMessage());
        }
        try {
            ManyMatchFields::resolve(self::index(), self::query(ManyMatch::Single, ['city'], ['name']));
            self::fail('expected InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame('manyMatch(): field "city" is not a many field with rank: best. Fields with it: (none).', $e->getMessage());
        }
    }
}
