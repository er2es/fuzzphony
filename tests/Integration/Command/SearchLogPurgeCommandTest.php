<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SearchLogPurgeCommand;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchLogPurgeCommandTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_search_log CASCADE');
        $this->engine = new PostgresEngine($this->connection, searchLog: true);
        $this->engine->globalSchema()->apply($this->connection);
    }

    private function record(string $query, string $ago): SearchRecord
    {
        return new SearchRecord(new \DateTimeImmutable($ago), 'products', null, null, $query, 1, 1.0, false);
    }

    public function testRowsOlderThanTheRetentionAreDeletedAndCounted(): void
    {
        $this->engine->writeSearchLog([
            $this->record('old a', '-40 days'),
            $this->record('old b', '-31 days'),
            $this->record('recent', '-29 days'),
            $this->record('new', '-1 minute'),
        ]);
        $tester = new CommandTester(new SearchLogPurgeCommand(new Fuzzphony($this->engine, new IndexRegistry([Indexes::products('manual')])), 30));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Deleted 2 search log rows older than 30 days.', $tester->getDisplay());
        self::assertEqualsCanonicalizing(
            ['recent', 'new'],
            array_column($this->connection->fetchAll('SELECT query FROM fuzzphony_search_log'), 'query'),
        );
    }

    public function testNothingToDeleteSaysZero(): void
    {
        $tester = new CommandTester(new SearchLogPurgeCommand(new Fuzzphony($this->engine, new IndexRegistry([Indexes::products('manual')])), 90));

        $tester->execute([]);

        self::assertStringContainsString('Deleted 0 search log rows older than 90 days.', $tester->getDisplay());
    }
}
