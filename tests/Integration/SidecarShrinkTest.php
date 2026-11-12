<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $this->connection->execute('DROP TABLE IF EXISTS sh_doc, fuzzphony_sh, fuzzphony_sh__next, fuzzphony_sh__changes, fuzzphony_sh__vocab, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    /** @param list<array{0: string, 1: string, 2: bool, 3?: string}> $fields name, weight, fuzzy, granted */
    private function fuzzphony(array $fields): Fuzzphony
    {
        $b = IndexDefinition::builder('sh')->fromTable('sh_doc');
        foreach ($fields as $f) {
            $b = $b->field($f[0], $f[1], fuzzy: $f[2], granted: $f[3] ?? null);
        }

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$b->sync('manual')->build()]));
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
