<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Observability\NullMetricsCollector;
use Fuzzphony\Engine\Postgres\EngineGuard;
use Fuzzphony\Engine\Postgres\IndexMaintenance;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\ShadowRebuild;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** An index that takes no changes from the shared log has nothing to drain and nothing pending, and the database is not even asked. */
final class IndexMaintenanceNoQueueTest extends TestCase
{
    public function testAnIndexWithoutTheSharedTriggerHasNothingToDrainOrPending(): void
    {
        $connection = new class implements Connection {
            public function fetchAll(string $sql, array $params = []): array
            {
                throw new \LogicException('the database must not be asked: ' . $sql);
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                throw new \LogicException('the database must not be asked: ' . $sql);
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('the database must not be asked: ' . $sql);
            }

            public function transactional(callable $callback): mixed
            {
                throw new \LogicException('the database must not be asked');
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
        $names = new Names('public', 'public');
        $schema = new PostgresSchemaGenerator($names, false);
        $maintenance = new IndexMaintenance($connection, $names, $schema, new ShadowRebuild($connection, $schema), new EngineGuard(new NullMetricsCollector()));

        foreach ([Indexes::products('manual'), Indexes::products('trigger')] as $index) {
            self::assertSame(0, $maintenance->drainChanges($index));
            self::assertSame(0, $maintenance->pendingChanges($index));
        }
    }
}
