<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * Sidecar shrink, step 1: a field column the refresh function would fill with the expression of another column is not kept
 * (`t_` of a one-field index = tsv, `z_` of the only fuzzy field = fz, `z_` of the primary field = exact). An install of an
 * earlier release has them: the apply drops them, idempotently, with no reindex, and a full reindex ends in the same table.
 */
final class SidecarShrinkTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->drop();
        $this->connection->execute('CREATE TABLE sh_doc (id integer PRIMARY KEY, title text, brand text, secret text)');
        $this->connection->execute("INSERT INTO sh_doc VALUES (1, 'Wireless Mouse', 'Logitech', 'alpha'), (2, 'Gaming Keyboard', 'Razer', 'mouse'), (3, 'Mouse Pad', 'Razer', 'beta')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        foreach (['one', 'open', 'two'] as $name) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS fuzzphony_%1$s, fuzzphony_%1$s__next, fuzzphony_%1$s__changes, fuzzphony_%1$s__vocab CASCADE', $name));
        }
        $this->connection->execute('DROP TABLE IF EXISTS sh_doc, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    private static function definition(string $name): IndexDefinition
    {
        return match ($name) {
            // one field: t_title = tsv, z_title = fz = exact
            'one' => IndexDefinition::builder('one')->fromTable('sh_doc')->field('title', 'A', fuzzy: true)->sync('manual')->build(),
            // title is the only fuzzy field and open; secret is granted: z_title = fz, the open trigram index is over fz
            'open' => IndexDefinition::builder('open')->fromTable('sh_doc')->field('title', 'B', fuzzy: true)->field('secret', 'A', granted: 'ROLE_SECRET')->sync('manual')->build(),
            // two fuzzy fields, neither primary: both keep their z_, every t_ stays
            default => IndexDefinition::builder('two')->fromTable('sh_doc')->field('secret', 'A')->field('title', 'B', fuzzy: true)->field('brand', 'C', fuzzy: true)->sync('manual')->build(),
        };
    }

    private function fuzzphony(string $name): Fuzzphony
    {
        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([self::definition($name)]));
    }

    /** @return list<string> */
    private function fieldColumns(string $name): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll(
            "SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(:t) AND attnum > 0 AND NOT attisdropped AND attname ~ '^[tz]_' ORDER BY attnum",
            ['t' => 'fuzzphony_' . $name],
        ), 'attname'));
    }

    /** What an install of 0.12.2 had: the copies, filled by its refresh function with the same expression as tsv / fz. */
    private function addOldCopies(string $name, string ...$assignments): void
    {
        foreach ($assignments as $assignment) {
            [$column, $source] = explode('=', $assignment);
            $type = str_starts_with($column, 't_') ? 'tsvector' : 'text';
            $this->connection->execute(sprintf("ALTER TABLE fuzzphony_%s ADD COLUMN %s %s NOT NULL DEFAULT ''", $name, $column, $type));
            $this->connection->execute(sprintf('UPDATE fuzzphony_%s SET %s = %s', $name, $column, $source));
        }
    }

    /** @return array<string, mixed> */
    private function answers(Fuzzphony $fuzzphony, string $index, ?array $allowed = null): array
    {
        $out = [];
        foreach (['mouse', 'mose', 'title:mouse', 'title:mose', '"wireless mouse"', 'mous*', 'mouse -pad', 'keybord'] as $q) {
            $b = $fuzzphony->in($index)->query($q)->explainMatches()->highlight('title');
            if ($allowed !== null) {
                $b = $b->restrictedToFields(...$allowed);
            }
            $r = $b->get();
            $out[$q] = array_map(static fn($h): array => [$h->id, round($h->score, 9), $h->highlights, array_map(static fn($m): array => $m->toArray(), $h->matches ?? [])], $r->hits);
        }

        return $out;
    }

    public function testAOneFieldIndexHasNeitherCopyAndTheApplyDropsTheOldOnesWithoutAReindex(): void
    {
        $fuzzphony = $this->fuzzphony('one');
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('one');
        self::assertSame([], $this->fieldColumns('one'), 'not created');
        $expected = $this->answers($fuzzphony, 'one');
        self::assertSame(1, $expected['mose'][0][0] ?? null, 'a typo is found');
        self::assertNotSame([], $expected['title:mose'], 'a scoped typo is found');

        $this->addOldCopies('one', 't_title=tsv', 'z_title=fz');
        self::assertSame(['t_title', 'z_title'], $this->fieldColumns('one'));
        self::assertSame(0, Coerce::int($this->connection->fetchValue('SELECT count(*) FROM fuzzphony_one WHERE t_title IS DISTINCT FROM tsv OR z_title IS DISTINCT FROM fz OR z_title IS DISTINCT FROM exact')));
        $warning = array_values(array_filter($fuzzphony->inspect('one')->problems(), static fn(Check $c): bool => $c->name === 'Sidecar columns'));
        self::assertCount(1, $warning);
        self::assertSame(CheckStatus::Warning, $warning[0]->status);
        self::assertSame('bin/console fuzzphony:schema --apply', $warning[0]->fix, 'the apply drops them');
        self::assertSame($expected, $this->answers($fuzzphony, 'one'), 'nothing reads them before the apply either');

        $fuzzphony->schema()->apply($this->connection);
        self::assertSame([], $this->fieldColumns('one'), 'dropped by the apply');
        self::assertSame($expected, $this->answers($fuzzphony, 'one'), 'no reindex needed');
        $fuzzphony->schema()->apply($this->connection);
        self::assertSame([], $this->fieldColumns('one'), 'idempotent');
        self::assertSame([], array_values(array_filter($fuzzphony->inspect('one')->problems(), static fn(Check $c): bool => $c->name === 'Sidecar columns')));

        // a write after the apply goes through the new refresh function, which writes neither column
        $this->connection->execute("UPDATE sh_doc SET title = 'Silent Mouse' WHERE id = 2");
        $fuzzphony->refresh('one', [2]);
        self::assertSame([2], $fuzzphony->in('one')->query('title:silnt')->get()->ids());
    }

    public function testAFullReindexBuildsTheTableWithoutTheOldCopies(): void
    {
        $fuzzphony = $this->fuzzphony('one');
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('one');
        $expected = $this->answers($fuzzphony, 'one');
        $this->addOldCopies('one', 't_title=tsv', 'z_title=fz');

        $fuzzphony->reindex('one');

        self::assertSame([], $this->fieldColumns('one'), 'the shadow table is built from the current layout');
        self::assertSame($expected, $this->answers($fuzzphony, 'one'));
    }

    public function testTheOnlyOpenFuzzyFieldReadsFzForEveryViewer(): void
    {
        $fuzzphony = $this->fuzzphony('open');
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('open');
        self::assertSame(['t_title', 't_secret'], $this->fieldColumns('open'), 'z_title is fz; secret is not fuzzy');
        $all = $this->answers($fuzzphony, 'open');
        $restricted = $this->answers($fuzzphony, 'open', ['title']);
        self::assertContains(2, array_column($all['mouse'], 0), 'document 2 has "mouse" only in the granted field');
        self::assertNotContains(2, array_column($restricted['mouse'], 0), 'a viewer without the granted field never finds it there');
        self::assertNotContains(2, array_column($restricted['mose'], 0));

        $this->addOldCopies('open', 'z_title=fz');
        self::assertSame($restricted, $this->answers($fuzzphony, 'open', ['title']));
        $fuzzphony->schema()->apply($this->connection);
        self::assertSame(['t_title', 't_secret'], $this->fieldColumns('open'));
        self::assertSame($restricted, $this->answers($fuzzphony, 'open', ['title']));
        self::assertSame($all, $this->answers($fuzzphony, 'open'));
    }

    public function testFuzzyFieldsThatAreNotTheOnlyOneNorPrimaryKeepTheirColumns(): void
    {
        $fuzzphony = $this->fuzzphony('two');
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('two');
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame(['t_secret', 't_title', 'z_title', 't_brand', 'z_brand'], $this->fieldColumns('two'));
        self::assertSame([3], $fuzzphony->in('two')->query('brand:razr title:pad')->get()->ids());
    }
}
