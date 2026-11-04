<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexSource;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Definition\Weight;
use PHPUnit\Framework\TestCase;

/** The exact words of the problems the validator reports: which thing they name and in which order. */
final class DefinitionValidatorMessagesTest extends TestCase
{
    private const string PRIMARY = ' is the primary field (the first A-weighted field, else the first one; the exact and prefix bonuses read one value) and cannot be "many": give another field weight A';

    private static function city(string $key = 'customer_id'): ManyValues
    {
        return new ManyValues('address', $key, 'city');
    }

    public function testAManyFieldWithAColumnACodeAndThePrimaryPlaceIsReportedByName(): void
    {
        $index = new IndexDefinition(name: 'customers', source: Source::table('customer'), fields: [new FieldDefinition('city', Weight::A, column: 'town', code: true, many: self::city())], sync: SyncMode::Manual);

        self::assertSame([
            'Field "city": "column" and "many" are exclusive: the column of the values is many.column.',
            'Field "city": "code" cannot be combined with "many" (a code field is read as one value).',
            'Field "city"' . self::PRIMARY . ' or put a single-valued field first.',
        ], DefinitionValidator::validate($index));
    }

    public function testThePrimaryFieldHintOnlyAsksForASingleValuedFirstFieldWhenItIsTheFirst(): void
    {
        $index = new IndexDefinition(name: 'customers', source: Source::table('customer'), fields: [new FieldDefinition('name', Weight::B), new FieldDefinition('city', Weight::A, many: self::city())], sync: SyncMode::Manual);

        self::assertSame(['Field "city"' . self::PRIMARY . '.'], DefinitionValidator::validate($index));
    }

    public function testAManyFilterOfTheWrongKindIsReportedByNameAndEveryProblemIsKept(): void
    {
        $index = new IndexDefinition(
            name: 'customers',
            source: Source::table('customer'),
            fields: [new FieldDefinition('name', Weight::A)],
            filters: [new FilterDefinition('tid', FilterType::Int, column: 'tenant', many: new ManyValues('address', 'customer_id', 'city', 0))],
            tenant: 'tid',
            sync: SyncMode::Manual,
        );

        $violations = DefinitionValidator::validate($index);

        self::assertCount(4, $violations);
        self::assertStringStartsWith('Filter "tid"', $violations[0], 'what the declaration itself says comes first');
        self::assertStringContainsString('between 1 and 10000', $violations[0]);
        self::assertSame('Filter "tid": "column" and "many" are exclusive: the column of the values is many.column.', $violations[1]);
        self::assertSame('Filter "tid" is a int filter: only string filters can take several values (many).', $violations[2]);
        self::assertSame('Filter "tid" is the tenant filter and cannot take several values (a document belongs to one tenant).', $violations[3]);
    }

    public function testTwoKeyColumnsForOneChildTableAreListedByName(): void
    {
        $index = new IndexDefinition(
            name: 'customers',
            source: Source::table('customer'),
            fields: [new FieldDefinition('name', Weight::A), new FieldDefinition('city', Weight::C, many: self::city('customer_id'))],
            filters: [new FilterDefinition('street', FilterType::String, many: new ManyValues('address', 'owner_id', 'street'))],
            sync: SyncMode::Manual,
        );

        self::assertSame(['The child table "address" is linked to the documents by different key columns (customer_id, owner_id): one table has one key.'], DefinitionValidator::validate($index));
    }

    public function testReturnableProblemsNameTheSourceOfAMultiSourceIndex(): void
    {
        $columns = array_map(static fn(int $i): string => 'c' . $i, range(1, 51));
        $source = new IndexSource('item', Source::table('item'), returnable: [...$columns, 'c1', 'bad col']);
        $index = new IndexDefinition(name: 'site', source: Source::table('item'), fields: [new FieldDefinition('title', Weight::A)], sync: SyncMode::Manual, sources: [$source]);

        self::assertSame([
            'Source "item": At most 50 returnable columns are allowed, got 53.',
            'Source "item": Returnable column "c1" is listed twice.',
            'Source "item": Returnable column "bad col" is not a valid column name.',
        ], DefinitionValidator::validate($index));
    }

    public function testReturnableProblemsOfASingleSourceIndexCarryNoPrefix(): void
    {
        $index = new IndexDefinition(name: 'items', source: Source::table('item'), fields: [new FieldDefinition('title', Weight::A)], sync: SyncMode::Manual, returnable: ['a', 'a', 'b']);

        self::assertSame(['Returnable column "a" is listed twice.'], DefinitionValidator::validate($index));
    }

    public function testASourceAndItsWatchesAreReportedWithTheirOwnNames(): void
    {
        $dollar = DefinitionValidator::DOLLAR_QUOTE_TAG;
        $watch = new Watch('bad table', 'DELETE FROM x WHERE id = :id ' . $dollar, 'bad key', ['good', 'bad col']);
        $index = new IndexDefinition(
            name: 'items',
            source: Source::query('UPDATE item SET x = 1 ' . $dollar, 'bad id'),
            fields: [new FieldDefinition('title', Weight::A)],
            watches: [$watch],
            sync: SyncMode::Queue,
        );

        self::assertSame([
            'Source query must be a SELECT (or WITH ... SELECT) statement.',
            'Source query must not contain "' . $dollar . '": it is embedded in generated function bodies quoted with that tag. Use another dollar-quote tag or a plain string literal.',
            'Id column "bad id" is not a valid column name.',
            'Watched table "bad table" is not a valid identifier.',
            'Watch key column "bad key" is not a valid column name.',
            'Watch on "bad table": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".',
            'Watch on "bad table": affectedIds must not contain "' . $dollar . '": it is embedded in generated trigger functions quoted with that tag. Use another dollar-quote tag or a plain string literal.',
            'Watch on "bad table": column "bad col" is not a valid column name.',
        ], DefinitionValidator::validate($index));
    }

    public function testATableSourceWithAnInvalidNameIsReportedAndAMultiSourceOnePrefixesIt(): void
    {
        $plain = new IndexDefinition(name: 'items', source: Source::table('bad table'), fields: [new FieldDefinition('title', Weight::A)], sync: SyncMode::Manual);
        self::assertSame(['Source table "bad table" is not a valid identifier (use "table" or "schema.table").'], DefinitionValidator::validate($plain));

        $multi = new IndexDefinition(name: 'site', source: Source::table('item'), fields: [new FieldDefinition('title', Weight::A)], sync: SyncMode::Manual, sources: [new IndexSource('item', Source::table('bad table'))]);
        self::assertSame(['Source "item": Source table "bad table" is not a valid identifier (use "table" or "schema.table").'], DefinitionValidator::validate($multi));
    }

    public function testASelectIsRecognisedOnlyAtTheStartOfTheIdsQuery(): void
    {
        $watch = new Watch('detail', 'DELETE FROM x WHERE id = (select :id)');
        $index = new IndexDefinition(name: 'items', source: Source::table('item'), fields: [new FieldDefinition('title', Weight::A)], watches: [$watch], sync: SyncMode::Queue);

        self::assertSame(['Watch on "detail": affectedIds must be a SELECT containing ":id" exactly once, e.g. "SELECT id FROM product WHERE brand_id = :id".'], DefinitionValidator::validate($index));
    }
}
