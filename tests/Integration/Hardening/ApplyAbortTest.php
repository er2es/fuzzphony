<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real database-side aborts of a fresh install's apply: an event trigger that rejects a statement, a conflicting object, and
 * the backend terminated in the middle of the apply's transaction. Each rolls back whole and a retry installs cleanly.
 */
#[Group('hardening')]
final class ApplyAbortTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    protected function tearDown(): void
    {
        $this->c->execute('DROP EVENT TRIGGER IF EXISTS fz_h_reject');
        $this->c->execute('DROP FUNCTION IF EXISTS public.fz_h_reject()');
    }

    /** @return array<string, list<string>> */
    private function reference(string $sync): array
    {
        $f = Harness::fuzzphony($this->c, [Indexes::products($sync)], 'fz_h_own');
        $f->schema()->apply($this->c);
        $catalog = Harness::catalog($this->c);
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);

        return $catalog;
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        foreach (['queue', 'trigger'] as $sync) {
            yield "{$sync}: CREATE FUNCTION" => [$sync, 'CREATE FUNCTION'];
            yield "{$sync}: CREATE TRIGGER" => [$sync, 'CREATE TRIGGER'];
            yield "{$sync}: CREATE TABLE" => [$sync, 'CREATE TABLE'];
            yield "{$sync}: ALTER TABLE" => [$sync, 'ALTER TABLE'];
            yield "{$sync}: CREATE TEXT SEARCH CONFIGURATION" => [$sync, 'CREATE TEXT SEARCH CONFIGURATION'];
        }
        yield 'queue: CREATE INDEX (a concurrent build)' => ['queue', 'CREATE INDEX'];
    }

    #[DataProvider('rejected')]
    public function testAnEventTriggerRejectingAStatementRollsBackAndARetryInstalls(string $sync, string $tag): void
    {
        $reference = $this->reference($sync);
        $this->c->execute("CREATE FUNCTION public.fz_h_reject() RETURNS event_trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'rejected by policy: %', tg_tag; END \$\$");
        $this->c->execute(sprintf("CREATE EVENT TRIGGER fz_h_reject ON ddl_command_end WHEN TAG IN ('%s') EXECUTE FUNCTION public.fz_h_reject()", $tag));
        $empty = Harness::catalog($this->c);
        $f = Harness::fuzzphony($this->c, [Indexes::products($sync)], 'fz_h_own');

        try {
            $f->schema()->apply($this->c);
            self::fail('the event trigger rejects ' . $tag);
        } catch (EngineFailure $e) {
            self::assertStringContainsString('rejected by policy', $e->getMessage());
        }
        if ($tag !== 'CREATE INDEX') {
            self::assertSame(Harness::catalog($this->c), $empty, 'nothing of the apply is left');
        } else {
            self::assertNotSame([], Harness::errors($f, 'products'), 'the doctor reports the missing index');
        }

        $this->c->execute('DROP EVENT TRIGGER fz_h_reject');
        $this->c->execute('DROP FUNCTION public.fz_h_reject()');
        $f->schema()->apply($this->c);
        self::assertSame($reference, Harness::catalog($this->c));
        $f->reindex('products');
        self::assertSame([], Harness::problems($f, 'products'));
    }

    public function testAConflictingObjectFailsTheApplyClearlyAndLeavesNothing(): void
    {
        // a view where the sidecar table goes: CREATE TABLE IF NOT EXISTS does not create it, the next statement fails
        $this->c->execute('CREATE SCHEMA fz_h_own');
        $this->c->execute('CREATE VIEW fz_h_own.fuzzphony_products AS SELECT 1 AS id');
        $empty = Harness::catalog($this->c);
        $f = Harness::fuzzphony($this->c, [Indexes::products('queue')], 'fz_h_own');

        try {
            $f->schema()->apply($this->c);
            self::fail('the view is in the way');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('fuzzphony_products', $e->getMessage());
        }
        self::assertSame($empty, Harness::catalog($this->c));
        self::assertNotSame([], Harness::errors($f, 'products'));

        $this->c->execute('DROP VIEW fz_h_own.fuzzphony_products');
        $f->schema()->apply($this->c);
        $f->reindex('products');
        self::assertSame([], Harness::problems($f, 'products'));
    }

    public function testTheBackendTerminatedInTheMiddleOfTheApplyRollsBack(): void
    {
        $reference = $this->reference('trigger');
        $empty = Harness::catalog($this->c);
        $victim = PdoConnection::fromDsn(PostgresTestCase::dsn());
        $pid = $victim->fetchValue('SELECT pg_backend_pid()');
        $killer = PostgresTestCase::connect();
        $f = Harness::fuzzphony($victim, [Indexes::products('trigger')], 'fz_h_own');

        // a second connection terminates the apply's backend in the middle of its transaction (at the trigger on fz_brand)
        $waiting = new \Fuzzphony\Tests\Integration\InterceptingConnection($victim, execute: static function (string $sql) use ($killer, $pid): void {
            if (str_contains($sql, 'fz_brand') && str_contains($sql, 'TRIGGER')) {
                $killer->execute(sprintf("DO \$\$ BEGIN PERFORM pg_terminate_backend(%d); END \$\$", (int) $pid));
            }
        });
        try {
            $f->schema()->apply($waiting);
            self::fail('the backend was terminated');
        } catch (\Throwable) {
        }
        self::assertSame($empty, Harness::catalog($this->c), 'the terminated transaction left nothing');

        $g = Harness::fuzzphony($this->c, [Indexes::products('trigger')], 'fz_h_own');
        $g->schema()->apply($this->c);
        self::assertSame($reference, Harness::catalog($this->c));
    }
}
