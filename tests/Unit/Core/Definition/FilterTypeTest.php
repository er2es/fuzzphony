<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Exception\InvalidQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilterTypeTest extends TestCase
{
    public function testFromPhpTypeMapsScalarTypes(): void
    {
        self::assertSame(FilterType::Float, FilterType::fromPhpType('float'));
        self::assertSame(FilterType::Float, FilterType::fromPhpType('?float'));
        self::assertSame(FilterType::String, FilterType::fromPhpType('string'));
        self::assertSame(FilterType::String, FilterType::fromPhpType('?string'));
        self::assertNull(FilterType::fromPhpType('array'));
    }

    public function testFloatNormalizesANumericString(): void
    {
        self::assertSame(3.5, FilterType::Float->normalize('3.5', 'price'));
    }

    public function testFloatRejectsANonNumericString(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "price" expects a float value, got string.');
        FilterType::Float->normalize('abc', 'price');
    }

    public function testDateRejectsAnUnparseableString(): void
    {
        // Triggers the internal \DateTimeImmutable parse failure, caught and turned into a mismatch.
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "created" expects a date (DateTimeInterface or Y-m-d string) value, got string.');
        FilterType::Date->normalize('not-a-real-date-at-all!!', 'created');
    }

    public function testDateRejectsAnEmptyString(): void
    {
        // Falls through toDate() without ever calling the constructor: empty string is not "is_string($v) && $v !== ''".
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "created" expects a date (DateTimeInterface or Y-m-d string) value, got string.');
        FilterType::Date->normalize('', 'created');
    }

    public function testDateRejectsANonStringNonDateValue(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "created" expects a date (DateTimeInterface or Y-m-d string) value, got int.');
        FilterType::Date->normalize(42, 'created');
    }

    public function testDateAcceptsADateTimeInterface(): void
    {
        self::assertSame('2024-01-02', FilterType::Date->normalize(new \DateTimeImmutable('2024-01-02T10:00:00Z'), 'created'));
    }

    /** @return iterable<string, array{string, FilterType}> */
    public static function dateTimeTypeNames(): iterable
    {
        yield 'interface' => ['DateTimeInterface', FilterType::DateTime];
        yield 'immutable' => ['\DateTimeImmutable', FilterType::DateTime];
        yield 'mutable, nullable' => ['?DateTime', FilterType::DateTime];
        yield 'bool' => ['bool', FilterType::Bool];
        yield 'int' => ['?int', FilterType::Int];
    }

    #[DataProvider('dateTimeTypeNames')]
    public function testFromPhpTypeMapsDateTimeClassesCaseInsensitively(string $type, FilterType $expected): void
    {
        self::assertSame($expected, FilterType::fromPhpType($type));
    }

    /** @return iterable<string, array{FilterType, mixed, bool|int|float|string}> */
    public static function acceptedValues(): iterable
    {
        yield 'bool' => [FilterType::Bool, true, true];
        yield 'int' => [FilterType::Int, 7, 7];
        yield 'negative int string' => [FilterType::Int, '-12', -12];
        yield 'int string' => [FilterType::Int, '12', 12];
        yield 'float from int' => [FilterType::Float, 3, 3.0];
        yield 'float' => [FilterType::Float, 2.5, 2.5];
        yield 'string' => [FilterType::String, 'abc', 'abc'];
        yield 'stringable' => [FilterType::String, new class implements \Stringable {
            public function __toString(): string
            {
                return 'from object';
            }
        }, 'from object'];
        yield 'date string' => [FilterType::Date, '2024-03-05 10:00:00', '2024-03-05'];
        yield 'datetime object' => [FilterType::DateTime, new \DateTimeImmutable('2024-03-05T10:00:00+00:00'), '2024-03-05T10:00:00+00:00'];
        yield 'datetime string' => [FilterType::DateTime, '2024-03-05T10:00:00+00:00', '2024-03-05T10:00:00+00:00'];
    }

    #[DataProvider('acceptedValues')]
    public function testNormalizeReturnsTheBindableScalar(FilterType $type, mixed $value, bool|int|float|string $expected): void
    {
        self::assertSame($expected, $type->normalize($value, 'f'));
    }

    /** @return iterable<string, array{FilterType, mixed, string}> */
    public static function rejectedValues(): iterable
    {
        yield 'bool from string' => [FilterType::Bool, 'true', 'bool value, got string'];
        yield 'int from float' => [FilterType::Int, 1.5, 'int value, got float'];
        yield 'int from text' => [FilterType::Int, '12abc', 'int value, got string'];
        yield 'int with a prefix' => [FilterType::Int, 'abc12', 'int value, got string'];
        yield 'int from decimal text' => [FilterType::Int, '1.5', 'int value, got string'];
        yield 'int from null' => [FilterType::Int, null, 'int value, got null'];
        yield 'float from array' => [FilterType::Float, [], 'float value, got array'];
        yield 'string from int' => [FilterType::String, 5, 'string value, got int'];
        yield 'string from null' => [FilterType::String, null, 'string value, got null'];
        yield 'string from a plain object' => [FilterType::String, new \stdClass(), 'string value, got stdClass'];
        yield 'datetime from garbage' => [FilterType::DateTime, 'definitely not a date', 'datetime (DateTimeInterface or ISO-8601 string) value, got string'];
    }

    #[DataProvider('rejectedValues')]
    public function testNormalizeRejectsValuesOfTheWrongType(FilterType $type, mixed $value, string $message): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Filter "f" expects a ' . $message . '.');
        $type->normalize($value, 'f');
    }
}
