<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use PHPUnit\Framework\TestCase;

final class SearchRecordTest extends TestCase
{
    public function testTheTextIsNormalisedForGrouping(): void
    {
        $record = new SearchRecord(new \DateTimeImmutable(), 'products', null, null, "  Wireless\t MOUSE  ", 3, 1.5, false);

        self::assertSame('wireless mouse', $record->normalised());
    }

    public function testAccentedCapitalsAreLowerCasedAsUnicode(): void
    {
        $record = new SearchRecord(new \DateTimeImmutable(), 'products', null, null, 'CRÈME', 0, 1.0, false);

        self::assertSame('crème', $record->normalised());
    }

    public function testTheStatusDefaultsToOk(): void
    {
        $record = new SearchRecord(new \DateTimeImmutable(), 'products', 3, 'ada', 'mouse', 1, 1.0, true);

        self::assertSame(RecordStatus::Ok, $record->status);
        self::assertSame(['ok', 'denied', 'rate_limited'], array_map(static fn(RecordStatus $s): string => $s->value, RecordStatus::cases()));
    }
}
