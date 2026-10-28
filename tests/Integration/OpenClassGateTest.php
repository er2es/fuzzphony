<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * The gate of the open-class indexes (TextConfigCache::openClass()): a restricted viewer who holds none of the granted fields looks its
 * words up in them only when every one exists, is valid and ready, and together they are smaller than `fz`; otherwise it gets today's
 * statements, and the results are the same either way. The answer is kept per process until resetCaches().
 */
final class OpenClassGateTest extends TestCase
{
    private const string OPEN_FZ = 'fuzzphony_gate_items_open_fz';
    private const string OPEN_TSV = 'fuzzphony_gate_items_open_tsv';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('SET synchronize_seqscans = off');
        $this->connection->execute('DROP TABLE IF EXISTS fz_gate_item, fuzzphony_gate_items, fuzzphony_gate_items__vocab, fuzzphony_gate_items__next, fuzzphony_gate_items__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_gate_item (id bigint PRIMARY KEY, name text NOT NULL, category text NOT NULL, brand text NOT NULL, description text NOT NULL)');
    }

    /** The granted fields hold most of the text: the open-class indexes are the smaller ones. */
    private function load(bool $grantedIsBig = true): void
    {
        mt_srand(7);
        $words = array_map(static fn(int $i): string => 'w' . dechex($i * 7919 % 4096) . 'x', range(1, 300));
        $text = static fn(int $n): string => implode(' ', array_map(static fn(): string => $words[mt_rand(0, 299)], range(1, $n)));
        for ($id = 1; $id <= 400; ++$id) {
            $this->connection->execute(
                'INSERT INTO fz_gate_item VALUES (:id, :name, :category, :brand, :description)',
                [
                    'id' => $id,
                    'name' => $id === 7 ? 'kettle red' : $text($grantedIsBig ? 2 : 40),
                    'category' => 'cat' . ($id % 5),
                    'brand' => $id === 8 ? 'zorbaxx' : $text($grantedIsBig ? 5 : 1),
                    'description' => $grantedIsBig ? $text(60) : 'x',
                ],
            );
        }
    }

    private function engine(): PostgresEngine
    {
        return new PostgresEngine($this->connection);
    }

    private function fuzzphony(PostgresEngine $engine, bool $granted = true): Fuzzphony
    {
        $definition = IndexDefinition::builder('gate_items')->fromQuery('SELECT id, name, category, brand, description FROM fz_gate_item')
            ->field('name', 'A', fuzzy: true)->field('category', 'B', fuzzy: true)
            ->field('brand', 'B', fuzzy: true, granted: $granted ? 'ROLE_BRAND' : null)->field('description', 'C', fuzzy: true, granted: $granted ? 'ROLE_DESCRIPTION' : null)
            ->language('english')->sync('manual')->build();

        return new Fuzzphony($engine, new IndexRegistry([$definition]));
    }

    private function applyAndLoad(PostgresEngine $engine, bool $grantedIsBig = true): Fuzzphony
    {
        $fuzzphony = $this->fuzzphony($engine);
        $fuzzphony->schema()->apply($this->connection);
        $this->load($grantedIsBig);
        $fuzzphony->reindex('gate_items');
        $this->connection->execute('ANALYZE fuzzphony_gate_items');

        return $fuzzphony;
    }

    /** @return array{open: bool, ids: list<int|string>, total: int} whether a statement looked up in the open class, and what was found */
    private function search(Fuzzphony $fuzzphony, string $text, ?array $allowed = ['name', 'category']): array
    {
        $builder = $fuzzphony->in('gate_items')->query($text)->thresholds(['fuzzy_mode' => 'always']);
        $explained = ($allowed === null ? $builder : $builder->restrictedToFields(...$allowed))->explain();
        $open = false;
        foreach ($explained->statements as $statement) {
            $open = $open || preg_match('/WHERE \(s\."t_name" \|\| s\."t_category"\) @@ q\.ts[qp]|<%\) \(s\."z_name" \|\| \' \' \|\| s\."z_category"\)/', $statement['sql']) === 1;
        }

        return ['open' => $open, 'ids' => array_map(static fn($hit): int|string => $hit->id, $explained->result->hits), 'total' => $explained->result->total];
    }

    public function testTheOpenClassIndexesAreUsedWhenTheyAreAllThereAndSmallerThanFz(): void
    {
        $fuzzphony = $this->applyAndLoad($this->engine());

        $found = $this->search($fuzzphony, 'kettle');
        self::assertTrue($found['open']);
        self::assertSame([7], $found['ids']);
        self::assertGreaterThan(0, $this->search($fuzzphony, 'kettel')['total']);
        // a viewer who holds a granted field, or no restriction, keeps fz and tsv
        self::assertFalse($this->search($fuzzphony, 'kettle', ['name', 'brand'])['open']);
        self::assertFalse($this->search($fuzzphony, 'kettle', null)['open']);
    }

    public function testAMissingIndexMeansTodaysStatementsWithTheSameResults(): void
    {
        $fuzzphony = $this->applyAndLoad($this->engine());
        $with = $this->search($fuzzphony, 'kettel');
        self::assertTrue($with['open']);

        foreach ([self::OPEN_FZ, self::OPEN_TSV] as $dropped) {
            $this->connection->execute('DROP INDEX ' . $dropped);
            $without = $this->search($this->fuzzphony($this->engine()), 'kettel');
            self::assertFalse($without['open'], $dropped . ' is missing');
            self::assertSame([$with['ids'], $with['total']], [$without['ids'], $without['total']]);
            $this->fuzzphony($this->engine())->schema()->apply($this->connection); // idempotent, and it builds the missing one back
            self::assertTrue($this->search($this->fuzzphony($this->engine()), 'kettel')['open'], $dropped . ' is built again');
        }
    }

    public function testAnInvalidOrUnreadyIndexIsNotUsed(): void
    {
        $fuzzphony = $this->applyAndLoad($this->engine());
        if (Coerce::str($this->connection->fetchValue("SELECT rolsuper::text FROM pg_roles WHERE rolname = current_user")) !== 'true') {
            self::markTestSkipped('Simulating an interrupted CREATE INDEX CONCURRENTLY edits pg_index: it needs a superuser.');
        }
        $with = $this->search($fuzzphony, 'kettel');
        foreach (['indisvalid', 'indisready'] as $flag) {
            $this->connection->execute(sprintf("UPDATE pg_index SET %s = false WHERE indexrelid = '%s'::regclass", $flag, self::OPEN_TSV));
            try {
                $without = $this->search($this->fuzzphony($this->engine()), 'kettel');
            } finally {
                $this->connection->execute(sprintf("UPDATE pg_index SET indisvalid = true, indisready = true WHERE indexrelid = '%s'::regclass", self::OPEN_TSV));
            }
            self::assertFalse($without['open'], $flag . ' is false');
            self::assertSame([$with['ids'], $with['total']], [$without['ids'], $without['total']]);
        }
        self::assertTrue($this->search($this->fuzzphony($this->engine()), 'kettel')['open'], 'valid again');
    }

    public function testTheAnswerIsKeptUntilTheCachesAreReset(): void
    {
        $engine = $this->engine();
        $fuzzphony = $this->applyAndLoad($engine);
        self::assertTrue($this->search($fuzzphony, 'kettle')['open']);

        $this->connection->execute('DROP INDEX ' . self::OPEN_FZ);
        self::assertTrue($this->search($fuzzphony, 'kettle')['open'], 'one process asks once');
        $engine->resetCaches();
        self::assertFalse($this->search($fuzzphony, 'kettle')['open']);

        // and the other way: an index built later is used after the reset, never before
        $fuzzphony->schema()->apply($this->connection);
        self::assertFalse($this->search($fuzzphony, 'kettle')['open']);
        $engine->resetCaches();
        self::assertTrue($this->search($fuzzphony, 'kettle')['open']);
    }

    public function testWhenTheGrantedFieldsHoldLittleTheOpenClassIndexesAreNotSmallerAndAreNotUsed(): void
    {
        $fuzzphony = $this->applyAndLoad($this->engine(), grantedIsBig: false);

        $found = $this->search($fuzzphony, 'kettle');
        self::assertFalse($found['open'], 'two indexes over nearly all the text are no shorter to read than fz');
        self::assertSame([7], $found['ids']);
    }

    public function testAnIndexWithoutAGrantedFieldHasNoOpenClassIndexes(): void
    {
        $fuzzphony = $this->fuzzphony($this->engine(), granted: false);
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame([], $this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_gate_items' AND indexname LIKE '%open%'"));
        $this->load();
        $fuzzphony->reindex('gate_items');
        self::assertFalse($this->search($fuzzphony, 'kettle')['open']);
    }
}
