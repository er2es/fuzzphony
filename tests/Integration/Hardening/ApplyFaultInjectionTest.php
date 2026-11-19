<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\InterceptingConnection;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The apply aborted at every statement of a fresh install's plan: the transaction rolls back whole, a concurrent index build
 * that is cancelled midway leaves an INVALID index the doctor reports, and the next apply finishes the install (the INVALID
 * index dropped and rebuilt) with the catalog of an install that never failed.
 */
#[Group('hardening')]
#[Group('slow')]
final class ApplyFaultInjectionTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    /** @return array{Fuzzphony, IndexDefinition} */
    private function install(Connection $c, string $sync, string $schema): array
    {
        $index = Indexes::products($sync);

        return [new Fuzzphony(new PostgresEngine($c, 'public', $schema, searchLog: true), new IndexRegistry([$index])), $index];
    }

    /** @return list<string> the SQL an apply executes on a fresh install, in order */
    private function executed(string $sync, string $schema): array
    {
        $log = new InterceptingConnection($this->c);
        [$f] = $this->install($log, $sync, $schema);
        $f->schema()->apply($log);
        $sql = [];
        foreach ($log->log as [$statement]) {
            $sql[] = $statement;
        }

        return $sql;
    }

    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        yield 'queue, public' => ['queue', 'public'];
        yield 'trigger, own schema' => ['trigger', 'fz_h_own'];
    }

    #[DataProvider('modes')]
    public function testAnAbortAtEveryStatementRollsBackOrLeavesARepairableState(string $sync, string $schema): void
    {
        $plan = $this->executed($sync, $schema);
        $this->c->execute('RESET ALL');
        $reference = Harness::catalog($this->c);
        $statements = count($plan);
        self::assertGreaterThan(20, $statements);

        for ($k = 0; $k < $statements; ++$k) {
            $this->c = Harness::fresh();
            Harness::fixtures($this->c);
            $empty = Harness::catalog($this->c);
            $writer = PostgresTestCase::connect();
            $seen = -1;
            $cic = false;
            $tx = false;
            $faulty = new InterceptingConnection(
                $this->c,
                fetchAll: function (string $sql) use (&$seen, $k, &$tx): ?array {
                    if (++$seen === $k) {
                        $tx = $this->c->inTransaction();
                        throw new \RuntimeException('injected fault at statement ' . $k);
                    }

                    return null;
                },
                fetchValue: function (string $sql) use (&$seen, $k, &$tx): ?array {
                    if (++$seen === $k) {
                        $tx = $this->c->inTransaction();
                        throw new \RuntimeException('injected fault at statement ' . $k);
                    }

                    return null;
                },
                execute: function (string $sql) use (&$seen, $k, $writer, &$cic, &$tx): void {
                    if (++$seen !== $k) {
                        return;
                    }
                    if (preg_match('/^CREATE INDEX CONCURRENTLY IF NOT EXISTS \S+ ON (\S+) /', $sql, $m) === 1) {
                        // a concurrent build cancelled midway (after its catalog entry was committed): it waits for the writer and times out
                        $cic = true;
                        $writer->execute('BEGIN');
                        $writer->execute(sprintf('LOCK TABLE %s IN ROW EXCLUSIVE MODE', $m[1]));
                        $this->c->execute("SET statement_timeout = '300ms'");

                        return;
                    }

                    $tx = $this->c->inTransaction();

                    throw new \RuntimeException('injected fault at statement ' . $k);
                },
            );
            [$f] = $this->install($faulty, $sync, $schema);
            try {
                $f->schema()->apply($faulty);
                $failed = false;
            } catch (\Throwable) {
                $failed = true;
            }
            if ($cic) {
                $writer->execute('ROLLBACK');
            }
            $this->c->execute('RESET ALL');
            self::assertFalse($this->c->inTransaction(), "statement {$k}: no transaction left open");
            [$g] = $this->install($this->c, $sync, $schema);
            self::assertTrue($failed || $k >= $statements, "statement {$k}: the fault surfaced");
            if ($tx) {
                self::assertSame($empty, Harness::catalog($this->c), "statement {$k} ({$plan[$k]}): the transaction rolled back whole");
            }
            if ($cic) {
                self::assertNotSame([], Harness::invalidIndexes($this->c), "statement {$k}: the cancelled build left an INVALID index");
                self::assertNotSame([], Harness::errors($g, 'products'), "statement {$k}: the doctor reports the INVALID index ({$plan[$k]})");
            }

            $g->schema()->apply($this->c);
            self::assertSame([], Harness::invalidIndexes($this->c), "statement {$k} ({$plan[$k]}): the retry left no INVALID index");
            self::assertSame($reference, Harness::catalog($this->c), "statement {$k} ({$plan[$k]}): the retry finished the install");
        }
    }
}
