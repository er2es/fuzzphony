<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Exception\InvalidQuery;
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
}
