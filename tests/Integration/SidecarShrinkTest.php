<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\SchemaNotApplied;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Schema\SchemaPlan;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\ColumnMap;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\OpenClass;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Schema\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sidecar shrink: a per-field column whose value another column holds (`z_` of the primary field = `exact`, of the only fuzzy
 * field = `fz`, `t_` of a one-field index = `tsv`) is written empty, and the other column is read instead, but only for a row
 * written with the current column map (`field_map`). Every definition change below is applied without the reindex first: a viewer
 * restricted to the open field `name` must never find a document by the text of `secret`, in the window or after the reindex.
 */
final class SidecarShrinkTest extends TestCase
{
    private const array HIDDEN = ['walrus', 'zanzibar', 'zanzibr', 'walrs', 'name:walrus', 'name:zanzibr'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->drop();
        $this->connection->execute('CREATE TABLE sh_doc (id integer PRIMARY KEY, name text NOT NULL, secret text NOT NULL)');
        $this->connection->execute("INSERT INTO sh_doc VALUES (1, 'kettle', 'zanzibar walrus'), (2, 'teapot', 'plain words')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS sh_doc, fuzzphony_sh__vals, fuzzphony_sh, fuzzphony_sh__next, fuzzphony_sh__changes, fuzzphony_sh__vocab, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    /** @param list<array{0: string, 1: string, 2: bool, 3?: ?string, 4?: bool}> $fields name, weight, fuzzy, granted, code */
    private function fuzzphony(array $fields, string $sync = 'manual'): Fuzzphony
    {
        $b = IndexDefinition::builder('sh')->fromTable('sh_doc');
        foreach ($fields as $f) {
            $b = $b->field($f[0], $f[1], fuzzy: $f[2], granted: $f[3] ?? null, code: $f[4] ?? false);
        }

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$b->sync($sync)->build()]));
    }

    /** @return list<int|string> */
    private static function ids(Fuzzphony $fuzzphony, string $query, bool $restricted = true): array
    {
        $b = $fuzzphony->in('sh')->query($query);

        return ($restricted ? $b->restrictedToFields('name') : $b)->get()->ids();
    }

    private function assertNothingHidden(Fuzzphony $fuzzphony, string $when): void
    {
        foreach (self::HIDDEN as $q) {
            self::assertSame([], self::ids($fuzzphony, $q), sprintf('%s: "%s" must not find a document by its granted field', $when, $q));
        }
    }

    /** @return iterable<string, array{list<list<array{0: string, 1: string, 2: bool, 3?: string}>>}> */
    public static function transitions(): iterable
    {
        $open = ['name', 'A', true];
        yield 'secret stops being fuzzy (z_name: exact stays exact)' => [[[$open, ['secret', 'B', true, 'ROLE_X']], [$open, ['secret', 'B', false, 'ROLE_X']]]];
        yield 'secret becomes fuzzy' => [[[$open, ['secret', 'B', false, 'ROLE_X']], [$open, ['secret', 'B', true, 'ROLE_X']]]];
        yield 'the primary moves from the granted field to name' => [[[['name', 'B', true], ['secret', 'A', true, 'ROLE_X']], [$open, ['secret', 'B', true, 'ROLE_X']]]];
        yield 'the primary moves from name to the granted field' => [[[$open, ['secret', 'B', true, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]]];
        yield 'name the only fuzzy field (fz), then secret fuzzy too (no map)' => [[[['name', 'B', true], ['secret', 'A', false, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]]];
        yield 'no map, then name the only fuzzy field (fz)' => [[[['name', 'B', true], ['secret', 'A', true, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', false, 'ROLE_X']]]];
        yield 'one field, then a granted field added' => [[[$open], [$open, ['secret', 'B', true, 'ROLE_X']]]];
        yield 'two fields, then the granted one removed' => [[[$open, ['secret', 'B', true, 'ROLE_X']], [$open]]];
        yield 'secret granted later' => [[[$open, ['secret', 'B', true]], [$open, ['secret', 'B', true, 'ROLE_X']]]];
        yield 'secret granted, then not' => [[[$open, ['secret', 'B', true, 'ROLE_X']], [$open, ['secret', 'B', true]]]];
        yield 'map, no map, the first map again (field_map dropped and re-added)' => [[
            [['name', 'B', true], ['secret', 'A', false, 'ROLE_X']],
            [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']],
            [['name', 'B', true], ['secret', 'A', false, 'ROLE_X']],
        ]];
    }

    /** @param list<list<array{0: string, 1: string, 2: bool, 3?: string}>> $steps */
    #[DataProvider('transitions')]
    public function testADefinitionChangeAppliedBeforeItsReindexNeverReadsAGrantedFieldsText(array $steps): void
    {
        $first = $this->fuzzphony($steps[0]);
        $first->schema()->apply($this->connection);
        $first->reindex('sh');
        if (count($steps[0]) > 1 && ($steps[0][1][3] ?? null) !== null) {
            $this->assertNothingHidden($first, 'steady state');
        }
        self::assertSame([1], self::ids($first, 'ketle'), 'steady state: a typo in the open field is found');

        $last = $first;
        foreach (array_slice($steps, 1) as $i => $fields) {
            $last = $this->fuzzphony($fields);
            $last->schema()->apply($this->connection);
            $this->assertNothingHidden($last, sprintf('after apply %d, before the reindex', $i + 2));
            // a document written in the window by the new refresh function
            $this->connection->execute("UPDATE sh_doc SET secret = 'zanzibar walrus again' WHERE id = 1");
            $last->refresh('sh', [1]);
            $this->assertNothingHidden($last, sprintf('after apply %d and a refresh', $i + 2));
            self::assertContains(1, self::ids($last, 'kettle', false), 'the open field is still found by the unrestricted viewer');
        }

        $last->reindex('sh');
        $this->assertNothingHidden($last, 'after the reindex');
        self::assertSame([1], self::ids($last, 'ketle'), 'after the reindex: a typo in the open field is found');
        self::assertSame([1], self::ids($last, 'name:ketle'));
        self::assertSame([1], self::ids($last, 'kettle'));
    }

    /**
     * The definition changes of the 0.12.4 review (F1, F2): after the apply alone (no reindex) every viewer finds exactly what 0.12.3
     * found. The reference is the same run with the rows put into the shape 0.12.3 wrote (every per-field column filled from the
     * column that holds its value, no map) before the change: 0.12.3 read exactly those values.
     *
     * @return iterable<string, array{0: list<list<array{0: string, 1: string, 2: bool, 3?: ?string, 4?: bool}>>, 1?: string}>
     */
    public static function matrix(): iterable
    {
        $open = ['name', 'A', true];
        $secret = ['secret', 'B', true, 'ROLE_X'];
        yield '1 one field, then a granted field added' => [[[$open], [$open, $secret]]];
        yield '2 two fields, then the second removed' => [[[$open, $secret], [$open]]];
        yield '2b two open fields, then the second removed' => [[[$open, ['secret', 'B', true]], [$open]]];
        yield '3a the granted field stops being fuzzy' => [[[['name', 'B', true], ['secret', 'A', true, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', false, 'ROLE_X']]]];
        yield '3b the granted field becomes fuzzy' => [[[['name', 'B', true], ['secret', 'A', false, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]]];
        yield '4 the weights swap between the granted and the open field' => [[[['name', 'B', true], ['secret', 'A', true, 'ROLE_X']], [['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]]];
        yield '4b one field, its weight changes' => [[[$open], [['name', 'C', true]]]];
        yield '5 the primary moves to the granted field' => [[[$open, $secret], [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]]];
        yield '5b the open primary stops being fuzzy' => [[[$open, ['other', 'B', true]], [['name', 'A', false], ['other', 'B', true]]]];
        yield '6 one field, the code flag toggled' => [[[$open], [['name', 'A', true, null, true]], [$open]]];
        yield '7 map, no map, the map again' => [[[['name', 'B', true], ['secret', 'A', false, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', true, 'ROLE_X']], [['name', 'B', true], ['secret', 'A', false, 'ROLE_X']]]];
        yield '8 a partial rewrite by refresh() in the window' => [[[$open], [$open, $secret]], 'refresh'];
        yield '8b a partial rewrite by the queue in the window' => [[[$open], [$open, $secret]], 'queue'];
        yield '8c a partial rewrite, then the definition changes again' => [[[$open], [$open, $secret], [$open]], 'refresh'];
        yield '9 the apply run twice' => [[[$open], [$open, $secret], [$open, $secret]]];
    }

    /** @param list<list<array{0: string, 1: string, 2: bool, 3?: ?string, 4?: bool}>> $steps */
    #[DataProvider('matrix')]
    public function testAfterTheApplyAloneEveryViewerFindsWhatTheEarlierReleaseFound(array $steps, string $window = ''): void
    {
        $reference = $this->scenario($steps, $window, true);
        $shrunk = $this->scenario($steps, $window, false);

        self::assertSame($reference, $shrunk);
    }

    /**
     * @param list<list<array{0: string, 1: string, 2: bool, 3?: ?string, 4?: bool}>> $steps
     *
     * @return list<array<string, array<int|string, float>>> what each viewer found after each apply
     */
    private function scenario(array $steps, string $window, bool $asWrittenBefore): array
    {
        $this->drop();
        $this->connection->execute('CREATE TABLE sh_doc (id integer PRIMARY KEY, name text NOT NULL, secret text NOT NULL, other text NOT NULL DEFAULT \'\')');
        $this->connection->execute("INSERT INTO sh_doc VALUES (1, 'kettle', 'zanzibar walrus', 'brass'), (2, 'teapot', 'plain words', 'kettle'), (3, 'kettle black', 'quiet notes', 'iron'), (4, 'copper kettle', 'walrus tusk', 'tin')");
        $sync = $window === 'queue' ? 'queue' : 'manual';
        $fuzzphony = $this->fuzzphony($steps[0], $sync);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('sh');
        if ($asWrittenBefore) {
            $this->unshrink($fuzzphony->definition('sh'));
        }

        $found = [];
        foreach (array_slice($steps, 1) as $i => $fields) {
            $fuzzphony = $this->fuzzphony($fields, $sync);
            $fuzzphony->schema()->apply($this->connection);
            if ($window !== '' && $i === 0) {
                $this->connection->execute("UPDATE sh_doc SET secret = secret || ' walrus' WHERE id IN (1, 3)");
                if ($window === 'queue') {
                    $fuzzphony->engine()->processQueue($fuzzphony->definition('sh'), 100);
                } else {
                    $fuzzphony->refresh('sh', [1, 3]);
                }
            }
            $this->assertNothingHidden($fuzzphony, sprintf('after apply %d', $i + 2));
            $found[] = $this->found($fuzzphony);
        }

        return $found;
    }

    /** The shape 0.12.3 wrote: every per-field column filled (from the column that holds its value), no map. */
    private function unshrink(IndexDefinition $definition): void
    {
        $sets = [];
        foreach ((new Names())->sharedColumns($definition) as $column => $from) {
            $sets[] = sprintf('%s = %s', $column, $from);
        }
        if ($this->connection->fetchValue("SELECT 1 FROM pg_attribute WHERE attrelid = 'fuzzphony_sh'::regclass AND attname = 'field_map' AND NOT attisdropped") !== null) {
            $sets[] = 'field_map = NULL';
        }
        if ($sets !== []) {
            $this->connection->execute('UPDATE fuzzphony_sh SET ' . implode(', ', $sets));
        }
    }

    /** @return array<string, array<int|string, float>> */
    private function found(Fuzzphony $fuzzphony): array
    {
        $queries = ['r' => ['kettle', 'ketle', 'name:kettle', 'name:ketle', 'copper ketle', 'teapot'], 'u' => ['kettle', 'ketle', 'walrus', 'walrs', 'name:kettle', 'name:ketle', 'copper kettle']];
        if ($fuzzphony->definition('sh')->field('secret') !== null) {
            array_push($queries['u'], 'secret:walrus', 'secret:walrs');
        }
        $found = [];
        foreach ($queries as $who => $list) {
            foreach ($list as $q) {
                $b = $fuzzphony->in('sh')->query($q);
                foreach (($who === 'r' ? $b->restrictedToFields('name') : $b)->get()->hits as $hit) {
                    $found[$who . ':' . $q][$hit->id] = round($hit->score, 6);
                }
                $found[$who . ':' . $q] ??= [];
            }
        }

        return $found;
    }

    /** @return iterable<string, array{array<string, bool>, bool, int, bool}> options, interactive, answer (1 yes, 0 no, -1 none), backfilled */
    public static function confirmations(): iterable
    {
        yield 'interactive, yes' => [[], true, 1, true];
        yield 'interactive, no' => [[], true, 0, false];
        yield 'non-interactive without --force' => [[], false, -1, false];
        yield 'non-interactive with --force' => [['--force' => true], false, -1, true];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('confirmations')]
    public function testSchemaApplyAsksBeforeTheBackfillAndTheDoctorWarnsUntilItRan(array $options, bool $interactive, int $answer, bool $backfilled): void
    {
        $one = $this->fuzzphony([['name', 'A', true]]);
        $one->schema()->apply($this->connection);
        $one->reindex('sh');
        $two = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $warning = static fn(): array => array_values(array_filter($two->inspect('sh')->checks, static fn(Check $c): bool => str_contains($c->message, 'another column map')));
        self::assertCount(1, $warning(), 'the doctor warns before the apply');
        self::assertStringContainsString('2 document(s)', $warning()[0]->message);
        self::assertSame('bin/console fuzzphony:schema --apply --force', $warning()[0]->fix);

        $tester = new CommandTester(new SchemaCommand($two, $this->connection));
        if ($answer >= 0) {
            $tester->setInputs([$answer === 1 ? 'yes' : 'no']);
        }
        $status = $tester->execute(['--apply' => true] + $options, ['interactive' => $interactive]);
        $display = $tester->getDisplay(true);

        self::assertStringContainsString('sh: 2 document(s) written with the map "t_name=tsv,z_name=exact"', $display);
        self::assertStringContainsString('That is 2 row(s) in 1 UPDATE statement(s)', $display);
        self::assertSame($answer >= 0, str_contains($display, 'Copy the columns back now?'));
        self::assertSame($backfilled ? Command::SUCCESS : Command::FAILURE, $status);
        self::assertSame($backfilled, !str_contains($display, 'The column backfill was skipped'));
        self::assertSame($backfilled ? [] : ['t_name=tsv,z_name=exact' => 2], ColumnMap::pending($this->connection, new Names(), $two->definition('sh')));
        self::assertCount($backfilled ? 0 : 1, $warning(), 'the doctor warns until the backfill ran');
        $this->assertNothingHidden($two, 'after the apply');
    }

    /**
     * Downgrade to 0.12.3, a definition change there (its refresh function writes no `field_map`), upgrade again: a row 0.12.3
     * rewrote keeps the map 0.12.4 gave it, but its `exact` holds another field now. Such maps are not trusted: never read as
     * shared, never backfilled; the doctor asks for the reindex.
     */
    public function testRowsAnEarlierReleaseRewroteAfterThisOneMappedThemAreNeverCopiedFrom(): void
    {
        $a = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $a->schema()->apply($this->connection);
        $a->reindex('sh');
        $b = $this->fuzzphony([['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]);
        // 0.12.3 with the primary moved to secret: its refresh function has no field_map, and it rewrote row 1 (every column filled)
        $this->connection->execute(sprintf('CREATE OR REPLACE FUNCTION %s(p_ids %s[]) RETURNS integer LANGUAGE plpgsql AS $f$ BEGIN RETURN 0; END $f$', (new Names())->refreshFunction($b->definition('sh')), Types::id($b->definition('sh')->idType)));
        $this->connection->execute("UPDATE fuzzphony_sh SET exact = 'zanzibar walrus', z_name = 'kettle', z_secret = 'zanzibar walrus' WHERE id = 1");

        $b->schema()->apply($this->connection);

        $this->assertNothingHidden($b, 'after the upgrade');
        self::assertSame('kettle', $this->connection->fetchValue('SELECT z_name FROM fuzzphony_sh WHERE id = 1'));
        $stale = array_values(array_filter($b->inspect('sh')->checks, static fn(Check $c): bool => str_contains($c->message, 'rewritten by an earlier release')));
        self::assertCount(1, $stale, 'the doctor asks for the reindex');
        self::assertStringContainsString('2 document(s)', $stale[0]->message);
        $b->reindex('sh');
        self::assertSame([1], self::ids($b, 'kettle'));
        $this->assertNothingHidden($b, 'after the reindex');
        self::assertSame([], array_values(array_filter($b->inspect('sh')->checks, static fn(Check $c): bool => str_contains($c->message, 'rewritten by an earlier release'))));
    }

    /** Nothing waits when the command counts: no question, but the backfill stays in the plan (a row of the old map may still arrive). */
    public function testWithNothingToBackfillTheCommandAsksNothingAndStillRunsTheBackfill(): void
    {
        $two = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $tester = new CommandTester(new SchemaCommand($two, $this->connection));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true], ['interactive' => true]));
        self::assertStringNotContainsString('Copy the columns back now?', $tester->getDisplay(true));
        self::assertStringContainsString(sprintf('%d statement(s) applied', count($two->schema()->statements)), $tester->getDisplay(true));
    }

    public function testTheDumpedMigrationHasTheBackfillAndNoPromptRuns(): void
    {
        $two = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $tester = new CommandTester(new SchemaCommand($two, $this->connection));
        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString(ColumnMap::BACKFILL, $tester->getDisplay(true));
        self::assertStringNotContainsString('Copy the columns back now?', $tester->getDisplay(true));
    }

    public function testCodeNewerThanTheSchemaFailsWithSchemaNotApplied(): void
    {
        $fuzzphony = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('sh');
        $this->connection->execute('ALTER TABLE fuzzphony_sh DROP COLUMN field_map');

        $this->expectException(SchemaNotApplied::class);
        $this->expectExceptionMessage('missing: field_map');
        self::ids($fuzzphony, 'ketle');
    }

    /** @return iterable<string, array{string}> */
    public static function races(): iterable
    {
        yield 'a refresh rewrites the row under the new map' => ['rewrite'];
        yield 'the row is deleted' => ['delete'];
    }

    /**
     * A batch of the backfill (another connection, a child process) waits on a row that a refresh rewrites under the current map
     * (or deletes) meanwhile: the row is skipped with its new version intact (never a copy of the new version's `exact`, which now
     * holds the granted field's text), and the batches go on to the end although that batch updated fewer rows.
     */
    #[DataProvider('races')]
    public function testABackfillBatchThatWaitsOnARowSkipsItWhenItChangedAndGoesOnToTheEnd(string $race): void
    {
        $this->connection->execute("INSERT INTO sh_doc VALUES (3, 'kettle black', 'quiet'), (4, 'copper kettle', 'tusk'), (5, 'old kettle', 'rust')");
        $a = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $a->schema()->apply($this->connection);
        $a->reindex('sh');
        $b = $this->fuzzphony([['name', 'B', true], ['secret', 'A', true, 'ROLE_X']]); // the primary moves to secret: z_secret=exact
        $definition = $b->definition('sh');
        $generator = new PostgresSchemaGenerator(new Names(), backfillBatch: 2);
        $plan = $generator->global($definition)->merge($generator->index($definition));
        $backfill = array_values(array_filter($plan->statements, static fn(Statement $s): bool => str_starts_with($s->description, ColumnMap::BACKFILL)))[0];
        (new SchemaPlan(array_values(array_filter($plan->statements, static fn(Statement $s): bool => $s !== $backfill))))->apply($this->connection);

        $writer = new \PDO(Coerce::str(getenv('FUZZPHONY_TEST_DSN')));
        $writer->beginTransaction();
        $writer->exec($race === 'rewrite' ? sprintf('SELECT %s(ARRAY[1]::%s[])', (new Names())->refreshFunction($definition), Types::id($definition->idType)) : 'DELETE FROM fuzzphony_sh WHERE id = 1');
        $sql = (string) tempnam(sys_get_temp_dir(), 'fzbf');
        file_put_contents($sql, $backfill->sql);
        $child = proc_open([PHP_BINARY, '-r', '$p = new PDO(getenv("FUZZPHONY_TEST_DSN")); $p->exec(file_get_contents($argv[1]));', '--', $sql], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        try {
            $waited = false;
            for ($i = 0; $i < 200 && !$waited; ++$i) {
                usleep(50_000);
                $waited = $this->connection->fetchValue("SELECT 1 FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND query LIKE 'DO %'") !== null;
            }
            self::assertTrue($waited, 'the batch waits on the row the writer holds');
        } finally {
            $writer->commit();
            $errors = (string) stream_get_contents($pipes[2]);
            proc_close($child);
            unlink($sql);
        }
        self::assertSame('', $errors);

        $rows = $this->connection->fetchAll('SELECT id, field_map, z_name, z_secret FROM fuzzphony_sh ORDER BY id');
        $expected = $race === 'rewrite' ? [['id' => 1, 'field_map' => 'z_secret=exact', 'z_name' => 'kettle', 'z_secret' => '']] : [];
        foreach ([2 => 'teapot', 3 => 'kettle black', 4 => 'copper kettle', 5 => 'old kettle'] as $id => $name) {
            $expected[] = ['id' => $id, 'field_map' => null, 'z_name' => $name, 'z_secret' => Coerce::str($this->connection->fetchValue('SELECT secret FROM sh_doc WHERE id = :id', ['id' => $id]))];
        }
        self::assertEquals($expected, $rows, 'the changed row keeps its new version; every other row is filled back');
        self::assertSame([], ColumnMap::pending($this->connection, new Names(), $definition));
        $this->assertNothingHidden($b, 'after the race');
    }

    public function testTheBackfillRunsInBatchesAndIsIdempotent(): void
    {
        $this->connection->execute("INSERT INTO sh_doc SELECT i, 'kettle ' || i, 'walrus ' || i FROM generate_series(3, 2500) AS i");
        $one = $this->fuzzphony([['name', 'A', true]]);
        $one->schema()->apply($this->connection);
        $one->reindex('sh');
        $two = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $definition = $two->definition('sh');
        self::assertSame(['t_name=tsv,z_name=exact' => 2500], ColumnMap::pending($this->connection, new Names(), $definition), 'the rows of the one-field map wait for the backfill');

        $generator = new PostgresSchemaGenerator(new Names(), backfillBatch: 300);
        $plan = $generator->global($definition)->merge($generator->index($definition));
        self::assertStringContainsString('LIMIT 300', $plan->toSql());
        $plan->apply($this->connection);
        self::assertSame([], ColumnMap::pending($this->connection, new Names(), $definition));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM fuzzphony_sh WHERE field_map IS NOT NULL OR t_name = ''::tsvector OR z_name = ''")));
        self::assertSame(2471, self::ids($two, 'kettle 2471')[0] ?? null);
        $plan->apply($this->connection);
        self::assertSame(2470, self::ids($two, 'kettle 2470')[0] ?? null);
        $this->assertNothingHidden($two, 'after the backfill');
    }

    public function testTheOpenClassTrigramIndexServesAMixedTable(): void
    {
        $definition = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']])->definition('sh');
        $this->connection->execute("INSERT INTO sh_doc SELECT i, 'kettle ' || i, 'walrus ' || i FROM generate_series(3, 400) AS i");
        $fuzzphony = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('sh');
        // half of the rows as an earlier release wrote them (no map, every column filled)
        $this->connection->execute('UPDATE fuzzphony_sh SET z_name = exact, field_map = NULL WHERE id % 2 = 0');
        $this->connection->execute('ANALYZE fuzzphony_sh');
        $names = new Names();
        $expression = OpenClass::trigram($definition, $names, 's.');
        self::assertNotNull($expression);
        $index = $names->indexName($definition, (string) array_key_first(OpenClass::indexes($definition, $names)));

        $this->connection->execute('SET enable_seqscan = off');
        try {
            $plan = implode("\n", array_map(static fn(array $r): string => Coerce::str(reset($r)), $this->connection->fetchAll(sprintf("EXPLAIN SELECT s.id FROM fuzzphony_sh AS s WHERE (%s) %% 'ketle'", $expression))));
            $found = Coerce::int($this->connection->fetchValue(sprintf("SELECT count(*) FROM fuzzphony_sh AS s WHERE (%s) LIKE '%%kettle%%'", $expression)));
        } finally {
            $this->connection->execute('RESET enable_seqscan');
        }
        self::assertStringContainsString($index, $plan);
        self::assertSame(399, $found, 'both kinds of rows are read');
    }

    public function testRowsOfAnEarlierReleaseAreReadFromTheirOwnColumnsAndTheReindexEmptiesThem(): void
    {
        $fuzzphony = $this->fuzzphony([['name', 'A', true], ['secret', 'B', true, 'ROLE_X']]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('sh');
        $expected = ['ketle' => self::ids($fuzzphony, 'ketle'), 'name:ketle' => self::ids($fuzzphony, 'name:ketle'), 'walrus' => self::ids($fuzzphony, 'walrus', false)];
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM fuzzphony_sh WHERE z_name <> '' OR field_map IS NULL")), 'z_name is written empty');

        // what 0.12.2 left: no column map, every per-field column filled
        $this->connection->execute('UPDATE fuzzphony_sh SET field_map = NULL, z_name = exact');
        self::assertSame($expected, ['ketle' => self::ids($fuzzphony, 'ketle'), 'name:ketle' => self::ids($fuzzphony, 'name:ketle'), 'walrus' => self::ids($fuzzphony, 'walrus', false)]);
        $this->connection->execute("UPDATE fuzzphony_sh SET z_name = ''");
        self::assertSame([], self::ids($fuzzphony, 'ketle'), 'such a row is read from its own column (here emptied to show it)');

        $fuzzphony->reindex('sh');
        self::assertSame($expected, ['ketle' => self::ids($fuzzphony, 'ketle'), 'name:ketle' => self::ids($fuzzphony, 'name:ketle'), 'walrus' => self::ids($fuzzphony, 'walrus', false)]);
    }
}
