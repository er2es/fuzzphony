<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The schema side of the open class (OpenClass): `schema --apply` builds the two indexes of an index with a granted field (concurrently,
 * and only once), the doctor reports a missing or invalid one with the statement that builds it, an install that has not applied since a
 * field became granted is told so, and a long-running process is asked to restart when the apply built them.
 */
final class OpenClassSchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_open_doc, fuzzphony_open_docs, fuzzphony_open_docs__vocab, fuzzphony_open_docs__next, fuzzphony_open_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_open_doc (id bigint PRIMARY KEY, name text NOT NULL, secret text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_open_doc VALUES (1, 'kettle', 'hidden words')");
    }

    private function fuzzphony(bool $granted): Fuzzphony
    {
        $definition = IndexDefinition::builder('open_docs')->fromQuery('SELECT id, name, secret FROM fz_open_doc')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: $granted ? 'ROLE_SECRET' : null)
            ->language('english')->sync('manual')->build();

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
    }

    /** @return list<string> */
    private function openIndexes(): array
    {
        return array_map(
            static fn(array $row): string => Coerce::str($row['indexname']),
            $this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_open_docs' AND indexname ~ '_open_(fz|tsv)_[0-9a-f]{8}$' ORDER BY indexname"),
        );
    }

    /** The name of the open-class index of one kind (open_fz / open_tsv) the table has now. */
    private function open(string $kind): string
    {
        return Coerce::str($this->connection->fetchValue("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_open_docs' AND indexname ~ '_open_" . $kind . "_[0-9a-f]{8}\$'"));
    }

    /** @return array<string, \Fuzzphony\Core\Inspection\Check> */
    private static function checks(Fuzzphony $fuzzphony): array
    {
        $byName = [];
        foreach ($fuzzphony->inspect('open_docs')->checks as $check) {
            $byName[$check->name] ??= $check;
        }

        return $byName;
    }

    public function testApplyBuildsTheTwoIndexesConcurrentlyAndOnlyOnce(): void
    {
        $fuzzphony = $this->fuzzphony(true);
        $plan = $fuzzphony->schema();
        $statements = array_values(array_filter(array_map(static fn($s): string => $s->sql, $plan->statements), static fn(string $sql): bool => preg_match('/^CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_(fz|tsv)_/', $sql) === 1));

        self::assertCount(2, $statements);
        self::assertMatchesRegularExpression('/^CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_fz_[0-9a-f]{8}" ON "public"."fuzzphony_open_docs" USING gin \(\("z_name"\) "public".gin_trgm_ops\)$/', $statements[0]);
        self::assertMatchesRegularExpression('/^CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_tsv_[0-9a-f]{8}" ON "public"."fuzzphony_open_docs" USING gin \(\("t_name"\)\)$/', $statements[1]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->schema()->apply($this->connection);
        self::assertCount(2, $this->openIndexes());
    }

    public function testAnIndexWithoutAGrantedFieldIsPlannedExactlyAsBefore(): void
    {
        $fuzzphony = $this->fuzzphony(false);
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame([], $this->openIndexes());
        foreach ($fuzzphony->schema()->statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/CREATE INDEX[^;]*_open_(fz|tsv)_/', $statement->sql);
        }
    }

    public function testTheDoctorSeesBothIndexesAndReportsAMissingOneWithTheStatementThatBuildsIt(): void
    {
        $fuzzphony = $this->fuzzphony(true);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('open_docs');
        $checks = self::checks($fuzzphony);
        $fz = $this->open('fz');
        $tsv = $this->open('tsv');
        self::assertSame(CheckStatus::Ok, $checks['Index ' . $fz]->status);
        self::assertSame(CheckStatus::Ok, $checks['Index ' . $tsv]->status);

        $this->connection->execute('DROP INDEX ' . $tsv);
        $check = self::checks($fuzzphony)['Index ' . $tsv];
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('missing', $check->message);
        self::assertSame(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS "%s" ON "public"."fuzzphony_open_docs" USING gin (("t_name"));', $tsv), $check->fix);
    }

    public function testAnInstallThatHasNotAppliedSinceAFieldBecameGrantedIsToldSo(): void
    {
        $this->fuzzphony(false)->schema()->apply($this->connection);
        $granted = $this->fuzzphony(true);
        $checks = self::checks($granted);

        self::assertSame(CheckStatus::Error, $checks['Definition']->status, 'the definition hash follows which fields are granted');
        $missing = array_filter($checks, static fn($check): bool => preg_match('/^Index fuzzphony_open_docs_open_(fz|tsv)_/', $check->name) === 1 && $check->status === CheckStatus::Error && $check->message === 'missing');
        self::assertCount(2, $missing);

        $granted->schema()->apply($this->connection);
        self::assertSame(CheckStatus::Ok, self::checks($granted)['Definition']->status);
    }

    public function testApplyAsksTheLongRunningProcessesToRestartWhenItBuiltTheIndexesOnATableThatWasThere(): void
    {
        $this->fuzzphony(false)->schema()->apply($this->connection);
        $tester = new CommandTester(new SchemaCommand($this->fuzzphony(true), $this->connection));

        $status = $tester->execute(['--apply' => true], ['interactive' => false]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '';
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('restart long-running processes', $display);
        self::assertStringContainsString('open-class indexes', $display);

        $tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertStringNotContainsString('restart', $tester->getDisplay(true), 'nothing was built the second time');
    }

    public function testAFullRebuildKeepsTheIndexesUnderTheirNames(): void
    {
        $definition = IndexDefinition::builder('open_docs')->fromQuery('SELECT id, name, secret FROM fz_open_doc')
            ->field('name', 'A', fuzzy: true)->field('secret', 'B', fuzzy: true, granted: 'ROLE_SECRET')->language('english')->sync('manual')->build();
        $engine = new PostgresEngine($this->connection);
        (new Fuzzphony($engine, new IndexRegistry([$definition])))->schema()->apply($this->connection);

        self::assertTrue($engine->beginRebuild($definition));
        $engine->refreshShadow($definition, [1]);
        $engine->finishRebuild($definition);

        self::assertCount(2, $this->openIndexes());
    }

    private function swapped(bool $secretIsGranted): Fuzzphony
    {
        // the open fields change with it: name in one, secret in the other
        $definition = IndexDefinition::builder('open_docs')->fromQuery('SELECT id, name, secret FROM fz_open_doc')
            ->field('name', 'A', fuzzy: true, granted: $secretIsGranted ? null : 'ROLE_NAME')->field('secret', 'B', fuzzy: true, granted: $secretIsGranted ? 'ROLE_SECRET' : null)
            ->language('english')->sync('manual')->build();

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
    }

    /** Whether a statement of a viewer who holds none of the granted fields looks its words up in the open-class index over `$column`. */
    private function usesOpenClass(Fuzzphony $fuzzphony, string $column): bool
    {
        $explained = $fuzzphony->in('open_docs')->query('kettle')->thresholds(['fuzzy_mode' => 'always'])->restrictedToFields($column)->explain();

        $sql = implode(' ', array_column($explained->statements, 'sql'));

        return str_contains($sql, 's."t_' . $column . '" @@ q.') && !str_contains($sql, 's.tsv @@');
    }

    /** The granted fields change on an applied index: the old open-class indexes are stale, no search uses them, the doctor says so, the apply fixes it. */
    public function testIndexesBuiltForOtherGrantedFieldsAreStaleUntilTheApplyReplacesThem(): void
    {
        // the granted name holds most of the text, the open secret little: the open-class indexes are the smaller ones
        $this->connection->execute("INSERT INTO fz_open_doc SELECT i, md5(i::text) || ' ' || md5((i + 1)::text) || ' ' || md5((i + 2)::text), 'kettle ' || i FROM generate_series(2, 400) AS i");
        $before = $this->swapped(true);
        $before->schema()->apply($this->connection);
        $before->reindex('open_docs');
        $this->connection->execute('ANALYZE fuzzphony_open_docs');
        $old = $this->openIndexes();
        self::assertCount(2, $old);

        $after = $this->swapped(false); // name is granted now, secret is open
        $checks = self::checks($after);
        foreach ($old as $name) {
            self::assertSame(CheckStatus::Error, $checks['Index ' . $name]->status, $name);
            self::assertStringContainsString('STALE', $checks['Index ' . $name]->message);
            self::assertStringContainsString('DROP INDEX CONCURRENTLY', (string) $checks['Index ' . $name]->fix);
        }
        $missing = array_filter($checks, static fn($check): bool => preg_match('/^Index fuzzphony_open_docs_open_(fz|tsv)_/', $check->name) === 1 && $check->message === 'missing');
        self::assertCount(2, $missing, 'the indexes the definition needs now are not there');
        self::assertFalse($this->usesOpenClass($after, 'secret'), 'the gate is false: today\'s statements');

        $after->schema()->apply($this->connection);
        $now = $this->openIndexes();
        self::assertCount(2, $now);
        self::assertSame([], array_intersect($old, $now), 'the stale indexes are gone, the right ones are there');
        foreach ($now as $name) {
            self::assertSame(CheckStatus::Ok, self::checks($after)['Index ' . $name]->status);
        }
        self::assertTrue($this->usesOpenClass($this->swapped(false), 'secret'), 'a new process uses them');
        $after->schema()->apply($this->connection);
        self::assertSame($now, $this->openIndexes(), 'idempotent');
    }

    /** No granted field any more: the open-class indexes are stale and the apply drops them; an index of the same table that is not ours stays. */
    public function testRemovingEveryGrantedFieldDropsTheOpenClassIndexesAndNothingElse(): void
    {
        $this->fuzzphony(true)->schema()->apply($this->connection);
        $this->connection->execute('CREATE INDEX fuzzphony_open_docs_open_fz_mine ON fuzzphony_open_docs (id)');
        self::assertCount(2, $this->openIndexes());

        $this->fuzzphony(false)->schema()->apply($this->connection);

        self::assertSame([], $this->openIndexes());
        self::assertSame(1, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_indexes WHERE indexname = 'fuzzphony_open_docs_open_fz_mine'")), 'only the hashed names of ours are dropped');
    }

    public function testAFreshInstallNeedsNoRestart(): void
    {
        $tester = new CommandTester(new SchemaCommand($this->fuzzphony(true), $this->connection));
        $tester->execute(['--apply' => true], ['interactive' => false]);

        self::assertStringNotContainsString('restart', $tester->getDisplay(true));
    }
}
