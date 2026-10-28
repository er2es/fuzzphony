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
            $this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_open_docs' AND indexname ~ '_open_(fz|tsv)$' ORDER BY indexname"),
        );
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
        $statements = array_values(array_filter(array_map(static fn($s): string => $s->sql, $plan->statements), static fn(string $sql): bool => preg_match('/_open_(fz|tsv)"/', $sql) === 1));

        self::assertSame([
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_fz" ON "public"."fuzzphony_open_docs" USING gin (("z_name") "public".gin_trgm_ops)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_tsv" ON "public"."fuzzphony_open_docs" USING gin (("t_name"))',
        ], $statements);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->schema()->apply($this->connection);
        self::assertSame(['fuzzphony_open_docs_open_fz', 'fuzzphony_open_docs_open_tsv'], $this->openIndexes());
    }

    public function testAnIndexWithoutAGrantedFieldIsPlannedExactlyAsBefore(): void
    {
        $fuzzphony = $this->fuzzphony(false);
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame([], $this->openIndexes());
        foreach ($fuzzphony->schema()->statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/_open_(fz|tsv)"/', $statement->sql);
        }
    }

    public function testTheDoctorSeesBothIndexesAndReportsAMissingOneWithTheStatementThatBuildsIt(): void
    {
        $fuzzphony = $this->fuzzphony(true);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('open_docs');
        $checks = self::checks($fuzzphony);
        self::assertSame(CheckStatus::Ok, $checks['Index fuzzphony_open_docs_open_fz']->status);
        self::assertSame(CheckStatus::Ok, $checks['Index fuzzphony_open_docs_open_tsv']->status);

        $this->connection->execute('DROP INDEX fuzzphony_open_docs_open_tsv');
        $check = self::checks($fuzzphony)['Index fuzzphony_open_docs_open_tsv'];
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('missing', $check->message);
        self::assertSame('CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_open_docs_open_tsv" ON "public"."fuzzphony_open_docs" USING gin (("t_name"));', $check->fix);
    }

    public function testAnInstallThatHasNotAppliedSinceAFieldBecameGrantedIsToldSo(): void
    {
        $this->fuzzphony(false)->schema()->apply($this->connection);
        $granted = $this->fuzzphony(true);
        $checks = self::checks($granted);

        self::assertSame(CheckStatus::Error, $checks['Definition']->status, 'the definition hash follows which fields are granted');
        self::assertSame(CheckStatus::Error, $checks['Index fuzzphony_open_docs_open_fz']->status);
        self::assertSame(CheckStatus::Error, $checks['Index fuzzphony_open_docs_open_tsv']->status);

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

        self::assertSame(['fuzzphony_open_docs_open_fz', 'fuzzphony_open_docs_open_tsv'], $this->openIndexes());
    }

    public function testAFreshInstallNeedsNoRestart(): void
    {
        $tester = new CommandTester(new SchemaCommand($this->fuzzphony(true), $this->connection));
        $tester->execute(['--apply' => true], ['interactive' => false]);

        self::assertStringNotContainsString('restart', $tester->getDisplay(true));
    }
}
