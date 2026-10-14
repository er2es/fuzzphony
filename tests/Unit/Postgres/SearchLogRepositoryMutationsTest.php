<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\SearchLogRepository;
use PHPUnit\Framework\TestCase;

/** What the search log repository binds: the tenant is always text, the column is text, whatever type the application uses for tenant ids. */
final class SearchLogRepositoryMutationsTest extends TestCase
{
    public function testAnIntegerTenantIsWrittenAsText(): void
    {
        $connection = new RecordingConnection(static fn(): null => null);
        $record = new SearchRecord(new \DateTimeImmutable('2026-01-02 03:04:05'), 'products', 7, null, 'mouse', 3, 1.5, false);

        (new SearchLogRepository($connection, new Names()))->write([$record]);

        self::assertCount(1, $connection->log);
        self::assertSame('7', $connection->log[0][1]['tn0']);
    }

    public function testANullTenantIsWrittenAsNull(): void
    {
        $connection = new RecordingConnection(static fn(): null => null);
        $record = new SearchRecord(new \DateTimeImmutable('2026-01-02 03:04:05'), 'products', null, null, 'mouse', 3, 1.5, false);

        (new SearchLogRepository($connection, new Names()))->write([$record]);

        self::assertNull($connection->log[0][1]['tn0']);
    }

    public function testAnIntegerTenantFilterOfTheReportIsBoundAsText(): void
    {
        $connection = new RecordingConnection(static fn(): null => null);

        (new SearchLogRepository($connection, new Names()))->top('products', new \DateTimeImmutable('2026-01-01'), null, 10, 42, false);

        self::assertCount(1, $connection->log);
        self::assertStringContainsString('tenant = :tn', $connection->log[0][0]);
        self::assertSame('42', $connection->log[0][1]['tn']);
    }
}
