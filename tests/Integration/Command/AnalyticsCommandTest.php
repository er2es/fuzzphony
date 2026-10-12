<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyticsCommandTest extends TestCase
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

    private function tester(bool $tenantScoped = false): CommandTester
    {
        return new CommandTester(new AnalyticsCommand(new Fuzzphony($this->engine, new IndexRegistry([Indexes::products('manual', tenant: $tenantScoped)]))));
    }

    private function record(string $query, int $hits = 1, string $ago = '-1 hour', int|string|null $tenant = null, RecordStatus $status = RecordStatus::Ok): SearchRecord
    {
        return new SearchRecord(new \DateTimeImmutable($ago), 'products', $tenant, null, $query, $hits, 1.0, false, $status);
    }

    /** @return list<array<string, mixed>> */
    private static function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($decoded);

        return $rows;
    }

    public function testTheTopQueriesAsJson(): void
    {
        $this->engine->writeSearchLog([$this->record('Mouse', 4), $this->record('mouse ', 2), $this->record('keyboard', 6)]);
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--top' => true, '--since' => '7d', '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $status);
        $rows = self::json($tester);
        self::assertCount(2, $rows);
        self::assertSame('mouse', $rows[0]['query']);
        self::assertSame(2, $rows[0]['searches']);
        self::assertEqualsWithDelta(3.0, $rows[0]['average_hits'], 0.001);
        self::assertIsString($rows[0]['last_seen']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $rows[0]['last_seen']));
        self::assertSame('keyboard', $rows[1]['query']);
    }

    public function testTopIsTheDefaultAndTheTableListsTheColumns(): void
    {
        $this->engine->writeSearchLog([$this->record('mouse', 4)]);
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products']);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/query\s+searches\s+avg hits\s+last seen/', $display);
        self::assertMatchesRegularExpression('/mouse\s+1\s+4\.0\s+\d{4}-\d{2}-\d{2} /', $display);
    }

    public function testZeroListsOnlyTheQueriesThatFoundNothing(): void
    {
        $this->engine->writeSearchLog([$this->record('mouse', 4), $this->record('mose', 0), $this->record('mose', 0)]);
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--zero' => true, '--format' => 'json']);

        $rows = self::json($tester);
        self::assertSame(['mose'], array_column($rows, 'query'));
        self::assertSame(2, $rows[0]['searches']);
    }

    public function testSinceNarrowsTheWindow(): void
    {
        $this->engine->writeSearchLog([$this->record('recent', ago: '-30 minutes'), $this->record('old', ago: '-3 days')]);

        $hour = $this->tester();
        $hour->execute(['index' => 'products', '--since' => '1h', '--format' => 'json']);
        $week = $this->tester();
        $week->execute(['index' => 'products', '--since' => '1w', '--format' => 'json']);
        $default = $this->tester();
        $default->execute(['index' => 'products', '--format' => 'json']);

        self::assertSame(['recent'], array_column(self::json($hour), 'query'));
        self::assertEqualsCanonicalizing(['recent', 'old'], array_column(self::json($week), 'query'));
        self::assertEqualsCanonicalizing(['recent', 'old'], array_column(self::json($default), 'query'), 'the default is 7d');
    }

    public function testTheLimitCapsTheList(): void
    {
        $this->engine->writeSearchLog([$this->record('a'), $this->record('b'), $this->record('c')]);
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--limit' => '2', '--format' => 'json']);

        self::assertCount(2, self::json($tester));
    }

    public function testAnEmptyReportIsAnEmptyJsonListAndASaidNothingInTheTable(): void
    {
        $json = $this->tester();
        $json->execute(['index' => 'products', '--format' => 'json']);
        $table = $this->tester();
        $table->execute(['index' => 'products']);

        self::assertSame('[]', trim($json->getDisplay()));
        self::assertStringContainsString('No searches', $table->getDisplay());
    }

    public function testATenantScopedIndexNeedsATenantOrAllTenants(): void
    {
        $tester = $this->tester(tenantScoped: true);

        $status = $tester->execute(['index' => 'products']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('allTenants', $tester->getDisplay());
    }

    public function testATenantReportAndTheAllTenantsReport(): void
    {
        $this->engine->writeSearchLog([$this->record('one', tenant: 1), $this->record('two', tenant: 2), $this->record('text', tenant: 'acme')]);

        $one = $this->tester(tenantScoped: true);
        $status = $one->execute(['index' => 'products', '--tenant' => '1', '--format' => 'json']);
        $text = $this->tester(tenantScoped: true);
        $text->execute(['index' => 'products', '--tenant' => 'acme', '--format' => 'json']);
        $all = $this->tester(tenantScoped: true);
        $all->execute(['index' => 'products', '--all-tenants' => true, '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['one'], array_column(self::json($one), 'query'), 'a digit string stays a string');
        self::assertSame(['text'], array_column(self::json($text), 'query'));
        self::assertEqualsCanonicalizing(['one', 'two', 'text'], array_column(self::json($all), 'query'));
    }

    /** @return iterable<string, array{string}> */
    public static function badSince(): iterable
    {
        yield 'garbage' => ['garbage'];
        yield 'no unit' => ['7'];
        yield 'unknown unit' => ['7m'];
        yield 'negative' => ['-7d'];
        yield 'trailing text' => ['7d '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSince')]
    public function testABadSinceIsInvalid(string $since): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--since' => $since]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--since', $tester->getDisplay());
    }

    public function testABadFormatIsInvalid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['index' => 'products', '--format' => 'xml']));
        self::assertStringContainsString('--format', $tester->getDisplay());
    }

    public function testTopAndZeroTogetherAreInvalid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['index' => 'products', '--top' => true, '--zero' => true]));
    }

    public function testTenantAndAllTenantsTogetherAreInvalid(): void
    {
        $tester = $this->tester(tenantScoped: true);

        self::assertSame(Command::INVALID, $tester->execute(['index' => 'products', '--tenant' => '1', '--all-tenants' => true]));
    }

    public function testALimitOutOfRangeIsReportedWithoutAStackTrace(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--limit' => '5000']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
    }

    public function testAnEngineWithoutALogIsReportedWithoutAStackTrace(): void
    {
        $engine = self::createStub(Engine::class);
        $tester = new CommandTester(new AnalyticsCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]))));

        $status = $tester->execute(['index' => 'products']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('keeps no search log', $tester->getDisplay());
    }
}
