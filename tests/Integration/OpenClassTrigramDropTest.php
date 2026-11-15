<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\OpenClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sidecar step 2 (0.12.5): when no granted field is typo-tolerant, the open-class trigram index (0.12.4 built it) copied `fz`, so it
 * is no longer built and the apply drops it; a restricted viewer looks its typos up in `fz` and its words in the open-class vector
 * index, which the gate now lets it use (the copy, never smaller than `fz`, kept the gate off). The reference for "what 0.12.4
 * found" is the same data with the open-class indexes dropped: the statements of `fz` / `tsv` that 0.12.4 sent while its gate was
 * off (always, for these definitions, since the copy was not smaller), and what the open-class lookups must equal by construction.
 * Nothing a restricted viewer finds may depend on a granted field it does not hold, in any step.
 */
final class OpenClassTrigramDropTest extends TestCase
{
    private const string NAME = 'otd';
    private const string TABLE = 'fuzzphony_otd';
    /** Words only the granted fields hold, and their typos: a viewer restricted to open fields never finds them. */
    private const array HIDDEN = ['zorbaxx', 'zorbax', 'walrus', 'walrs', 'name:zorbax'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('SET synchronize_seqscans = off');
        $this->drop();
        $this->connection->execute('CREATE TABLE otd_item (id bigint PRIMARY KEY, name text NOT NULL, category text NOT NULL, secret text NOT NULL, note text NOT NULL, kind text NOT NULL)');
        $this->load(1);
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS otd_item, fuzzphony_otd, fuzzphony_otd__vocab, fuzzphony_otd__next, fuzzphony_otd__changes, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    /** 300 rows; the granted fields hold most of the text (so the open vector index is the smaller one). $seed varies only them. */
    private function load(int $seed): void
    {
        $this->connection->execute('TRUNCATE otd_item');
        $open = ['kettle', 'teapot', 'copper', 'black', 'red', 'iron', 'mug', 'spoon', 'brass', 'cup'];
        $filler = array_map(static fn(int $i): string => 'g' . dechex($i * 7919 % 4096) . 'q', range(1, 200));
        mt_srand(11);
        $rows = [];
        for ($id = 1; $id <= 300; ++$id) {
            $name = $id % 17 === 0 ? '' : $open[mt_rand(0, 9)] . ' ' . $open[mt_rand(0, 9)];
            $rows[$id] = [$name, 'cat' . ($id % 4), 'k' . ($id % 3)];
        }
        mt_srand(100 + $seed);
        foreach ($rows as $id => [$name, $category, $kind]) {
            $granted = static fn(int $n): string => implode(' ', array_map(static fn(): string => $filler[mt_rand(0, 199)], range(1, $n)));
            $secret = $granted(40) . ($id % (5 + $seed) === 0 ? ' zorbaxx kettle red' : '') . ($id % (7 + $seed) === 0 ? ' walrus copper' : '');
            $note = $granted(30) . ($id % (9 + $seed) === 0 ? ' walrus teapot' : '');
            $this->connection->execute('INSERT INTO otd_item VALUES (:id, :name, :category, :secret, :note, :kind)', ['id' => $id, 'name' => $name, 'category' => $category, 'secret' => $secret, 'note' => $note, 'kind' => $kind]);
        }
    }

    /**
     * @param array<string, array{0: string, 1: bool, 2?: ?string}> $fields name => [weight, fuzzy, granted]
     */
    private function fuzzphony(array $fields): Fuzzphony
    {
        $b = IndexDefinition::builder(self::NAME)->fromTable('otd_item');
        foreach ($fields as $name => $f) {
            $b = $b->field($name, $f[0], fuzzy: $f[1], granted: $f[2] ?? null);
        }

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$b->filter('kind', 'string')->language('english')->sync('manual')->build()]));
    }

    /** @return array<string, array<string, array{0: string, 1: bool, 2?: ?string}>> the definitions of the matrix */
    private static function shapes(): array
    {
        return [
            'granted fuzzy' => ['name' => ['A', true], 'category' => ['B', true], 'secret' => ['B', true, 'ROLE_S'], 'note' => ['C', false, 'ROLE_N']],
            'granted non-fuzzy' => ['name' => ['A', true], 'category' => ['B', true], 'secret' => ['B', false, 'ROLE_S'], 'note' => ['C', false, 'ROLE_N']],
            'open only' => ['name' => ['A', true], 'category' => ['B', true], 'secret' => ['B', true], 'note' => ['C', false]],
            'one open fuzzy field' => ['name' => ['A', true], 'category' => ['B', false], 'secret' => ['B', false, 'ROLE_S'], 'note' => ['C', false, 'ROLE_N']],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function shapeNames(): iterable
    {
        foreach (array_keys(self::shapes()) as $shape) {
            yield $shape => [$shape];
        }
    }

    /** The name and body 0.12.4 gave the open-class trigram index of a definition (null: it built none). @return array{string, string}|null */
    private static function oldTrigram(IndexDefinition $index, Names $names): ?array
    {
        $open = OpenClass::fields($index);
        $columns = array_map(static fn(FieldDefinition $f): string => $names->fieldFuzzy($index, $f->name, ''), array_values(array_filter($open, static fn(FieldDefinition $f): bool => $f->fuzzy)));
        if ($columns === []) {
            return null;
        }
        $body = sprintf('USING gin ((%s) %s.gin_trgm_ops)', implode(" || ' ' || ", $columns), $names->extension());

        return [$names->indexName($index, OpenClass::TRIGRAM . '_' . hash('crc32b', $body)), $body];
    }

    /** @return list<string> the open-class indexes the table has */
    private function openIndexes(): array
    {
        return array_map(Coerce::str(...), array_column($this->connection->fetchAll("SELECT indexname FROM pg_indexes WHERE tablename = 'fuzzphony_otd' AND indexname ~ '_open_(fz|tsv)_[0-9a-f]{8}$' ORDER BY 1"), 'indexname'));
    }

    private function dropOpenClass(): void
    {
        foreach ($this->openIndexes() as $name) {
            $this->connection->execute('DROP INDEX ' . $name);
        }
    }

    /**
     * What each viewer finds: ids, scores, totals, facets, highlights, for plain words, typos, phrases, exclusions, scoped words and
     * prefixes, in both typo modes.
     *
     * @return array<string, mixed>
     */
    private function found(Fuzzphony $fuzzphony): array
    {
        $definition = $fuzzphony->definition(self::NAME);
        $viewers = ['unrestricted' => null, 'open' => ['name', 'category'], 'name' => ['name']];
        foreach (['secret', 'note'] as $granted) {
            if ($definition->field($granted)?->granted !== null) {
                $viewers['holds ' . $granted] = ['name', $granted];
            }
        }
        $queries = ['kettle', 'kettel', 'ketle red', 'name:kettel', '"kettle red"', 'kettle -red', 'coper', 'cat1 kettel', 'teapto mug', 'zorbaxx', 'zorbax', 'walrus', 'walrs', 'name:zorbax', 'kett*', 'tea'];
        $found = [];
        $engine = $fuzzphony->engine();
        \assert($engine instanceof PostgresEngine);
        $engine->resetCaches();
        foreach ($viewers as $who => $allowed) {
            foreach ($queries as $q) {
                foreach (['always', 'fallback'] as $mode) {
                    $b = $fuzzphony->in(self::NAME)->query($q)->thresholds(['typo' => ['mode' => $mode]])->facets('kind')->highlight('name')->exactCounts()->limit(8)->asYouType($q === 'tea');
                    $r = ($allowed === null ? $b : $b->restrictedToFields(...$allowed))->get();
                    $facets = [];
                    foreach ($r->facets as $f => $values) {
                        foreach ($values as $v) {
                            $facets[$f][] = [(string) $v->value, $v->count];
                        }
                    }
                    $found["$who | $mode | $q"] = [
                        'total' => $r->total,
                        'hits' => array_map(static fn($h): array => [$h->id, round($h->score, 9), $h->highlights], $r->hits),
                        'facets' => $facets,
                    ];
                }
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $found */
    private static function assertNothingHidden(array $found, string $when): void
    {
        self::assertGreaterThan(0, Coerce::int(((array) $found['unrestricted | always | zorbaxx'])['total']), $when . ': the hidden words are in the data');
        foreach ($found as $key => $result) {
            [$who, , $q] = explode(' | ', $key);
            if (in_array($who, ['open', 'name'], true) && in_array($q, self::HIDDEN, true)) {
                self::assertSame(0, Coerce::int(((array) $result)['total']), sprintf('%s: "%s" (%s) must not find a document by a granted field', $when, $q, $who));
            }
        }
    }

    public function testNoGrantedFuzzyFieldBuildsNoTrigramCopyAndTheViewerGetsTheOpenVectorIndex(): void
    {
        $fuzzphony = $this->fuzzphony(self::shapes()['granted non-fuzzy']);
        $names = new Names();
        $definition = $fuzzphony->definition(self::NAME);
        self::assertNull(OpenClass::trigram($definition, $names));
        self::assertSame(['open_tsv'], array_map(static fn(string $s): string => substr($s, 0, 8), array_keys(OpenClass::indexes($definition, $names))));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex(self::NAME);
        $this->connection->execute('ANALYZE ' . self::TABLE);
        self::assertCount(1, $this->openIndexes());

        $sql = implode("\n", array_column($fuzzphony->in(self::NAME)->query('kettel red')->thresholds(['typo' => ['mode' => 'always']])->restrictedToFields('name', 'category')->explain(false)->statements, 'sql'));
        self::assertMatchesRegularExpression('/\(s\."t_name" \|\| s\."t_category"\) @@ q\.ts/', $sql, 'the open-class vector lookup (the gate is on now)');
        self::assertStringNotContainsString("<%) (s.\"z_name\" || ' ' || s.\"z_category\")", $sql, 'no typo lookup on the copy of fz');
        self::assertDoesNotMatchRegularExpression('/__fz|__tsv|_open_/', $sql, 'no statement names an index');

        $this->connection->execute('SET enable_seqscan = off');
        try {
            $plan = implode("\n", array_map(static fn(array $r): string => Coerce::str(reset($r)), $this->connection->fetchAll("EXPLAIN SELECT s.id FROM fuzzphony_otd AS s WHERE 'ketle' OPERATOR(public.<%) s.fz OR (s.t_name || s.t_category) @@ to_tsquery('english', 'kettl')")));
        } finally {
            $this->connection->execute('RESET enable_seqscan');
        }
        self::assertStringContainsString('fuzzphony_otd__fz', $plan);
        self::assertMatchesRegularExpression('/fuzzphony_otd__open_tsv_[0-9a-f]{8}/', $plan);
    }

    /** For every row the current definition wrote, the expression of the dropped index has the trigrams of `fz` (pg_trgm ignores the separators). */
    #[DataProvider('shapeNames')]
    public function testTheDroppedExpressionHasTheTrigramsOfFzForEveryRow(string $shape): void
    {
        $fuzzphony = $this->fuzzphony(self::shapes()[$shape]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex(self::NAME);
        $definition = $fuzzphony->definition(self::NAME);
        $names = new Names();
        $grantedFuzzy = array_any($definition->fields, static fn(FieldDefinition $f): bool => $f->granted !== null && $f->fuzzy);
        $old = OpenClass::fields($definition) === [] ? null : implode(" || ' ' || ", array_map(static fn(FieldDefinition $f): string => $names->fieldFuzzy($definition, $f->name), array_values(array_filter(OpenClass::fields($definition), static fn(FieldDefinition $f): bool => $f->fuzzy))));
        if ($old === null || $grantedFuzzy) {
            self::assertSame($grantedFuzzy, OpenClass::trigram($definition, $names) !== null, 'kept exactly when a granted field is fuzzy');

            return;
        }
        self::assertNull(OpenClass::trigram($definition, $names));
        self::assertSame(0, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM %s AS s WHERE show_trgm(%s) IS DISTINCT FROM show_trgm(s.fz)', self::TABLE, $old))));
        self::assertTrue(!str_contains($old, "|| ' ' ||") || Coerce::int($this->connection->fetchValue(sprintf("SELECT count(*) FROM %s AS s WHERE (%s) LIKE ' %%'", self::TABLE, $old))) > 10, 'rows with an empty first field are in the data');
    }

    /**
     * The apply drops the index 0.12.4 built (catalog-checked, CONCURRENTLY, idempotent; the doctor calls it stale before), and
     * afterwards every viewer finds what it found with the 0.12.4 statements; a viewer restricted to open fields finds nothing by a
     * granted field, and what it finds does not change when only the granted fields' text changes.
     */
    #[DataProvider('shapeNames')]
    public function testAfterTheApplyEveryViewerFindsWhatItFoundBeforeAndNothingDependsOnAGrantedField(string $shape): void
    {
        $fuzzphony = $this->fuzzphony(self::shapes()[$shape]);
        $definition = $fuzzphony->definition(self::NAME);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex(self::NAME);
        $old = self::oldTrigram($definition, new Names());
        if ($old !== null && OpenClass::trigram($definition, new Names()) === null) {
            // the index as 0.12.4 left it
            $this->connection->execute(sprintf('CREATE INDEX %s ON %s %s', $old[0], self::TABLE, $old[1]));
            $stale = array_filter($fuzzphony->inspect(self::NAME)->checks, static fn(Check $c): bool => str_contains($c->name, $old[0]) && str_contains($c->message, 'STALE'));
            self::assertCount(1, $stale, 'the doctor calls the copy stale until the apply');
            $fuzzphony->schema()->apply($this->connection);
            self::assertNotContains($old[0], $this->openIndexes(), 'the apply dropped it');
            $fuzzphony->schema()->apply($this->connection);
            self::assertSame(array_map(static fn(string $n): string => $n, OpenClass::expectedNames($definition, new Names())), $this->openIndexes(), 'idempotent: the expected ones, nothing else');
        }
        $this->connection->execute('ANALYZE ' . self::TABLE);

        $now = $this->found($fuzzphony);
        self::assertNothingHidden($now, $shape);
        $this->dropOpenClass();
        self::assertSame($this->found($fuzzphony), $now, 'the statements of fz / tsv (0.12.4 with its gate off) find the same');

        $fuzzphony->schema()->apply($this->connection);
        $this->load(2); // only the granted fields' text changes
        $fuzzphony->reindex(self::NAME);
        $this->connection->execute('ANALYZE ' . self::TABLE);
        $other = $this->found($fuzzphony);
        self::assertNothingHidden($other, $shape . ', other granted text');
        foreach ($now as $key => $result) {
            if (str_starts_with($key, 'open | ') || str_starts_with($key, 'name | ')) {
                self::assertSame($result, $other[$key], sprintf('%s: %s depends on a granted field', $shape, $key));
            }
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function transitions(): iterable
    {
        yield 'a granted fuzzy field added' => [['open only', 'granted fuzzy']];
        yield 'the granted fuzzy field removed (made open)' => [['granted fuzzy', 'open only']];
        yield 'the granted field stops being fuzzy' => [['granted fuzzy', 'granted non-fuzzy']];
        yield 'the granted field becomes fuzzy' => [['granted non-fuzzy', 'granted fuzzy']];
        yield 'name becomes the only fuzzy field' => [['granted fuzzy', 'one open fuzzy field']];
        yield 'name stops being the only fuzzy field' => [['one open fuzzy field', 'granted fuzzy']];
        yield 'the primary moves to the granted field' => [['granted non-fuzzy', 'primary granted']];
        yield 'the primary moves back to name' => [['primary granted', 'granted non-fuzzy']];
        yield 'the primary moves to a granted fuzzy field' => [['granted non-fuzzy', 'primary granted fuzzy']];
        yield 'the apply run twice' => [['granted fuzzy', 'granted non-fuzzy', 'granted non-fuzzy']];
        yield 'there and back' => [['granted fuzzy', 'granted non-fuzzy', 'granted fuzzy']];
    }

    /**
     * Each definition change applied without the reindex: the open-class trigram index is there exactly when a granted field is
     * fuzzy, and every viewer finds what the statements of fz / tsv find on the same rows (the 0.12.4 reference: the open-class
     * lookup is only a superset that the recheck narrows), with a document rewritten in the window too; never by a granted field.
     *
     * @param list<string> $steps
     */
    #[DataProvider('transitions')]
    public function testEachDefinitionChangeAppliedWithoutTheReindex(array $steps): void
    {
        $shapes = self::shapes() + [
            'primary granted' => ['name' => ['B', true], 'category' => ['C', true], 'secret' => ['A', false, 'ROLE_S'], 'note' => ['C', false, 'ROLE_N']],
            'primary granted fuzzy' => ['name' => ['B', true], 'category' => ['C', true], 'secret' => ['A', true, 'ROLE_S'], 'note' => ['C', false, 'ROLE_N']],
        ];
        $fuzzphony = $this->fuzzphony($shapes[$steps[0]]);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex(self::NAME);
        foreach (array_slice($steps, 1) as $i => $shape) {
            $fuzzphony = $this->fuzzphony($shapes[$shape]);
            $definition = $fuzzphony->definition(self::NAME);
            $fuzzphony->schema()->apply($this->connection);
            if ($i === 0) {
                $this->connection->execute("UPDATE otd_item SET secret = secret || ' zorbaxx kettle', note = note || ' walrus' WHERE id IN (3, 4, 30)");
                $fuzzphony->refresh(self::NAME, [3, 4, 30]);
            }
            $this->connection->execute('ANALYZE ' . self::TABLE);
            $grantedFuzzy = array_any($definition->fields, static fn(FieldDefinition $f): bool => $f->granted !== null && $f->fuzzy);
            self::assertSame($grantedFuzzy, array_any($this->openIndexes(), static fn(string $n): bool => str_contains($n, '_open_fz_')), sprintf('step %d (%s): the trigram index exactly when a granted field is fuzzy', $i + 2, $shape));
            self::assertSame(OpenClass::expectedNames($definition, new Names()), $this->openIndexes(), 'nothing stale is left');

            $now = $this->found($fuzzphony);
            self::assertNothingHidden($now, sprintf('step %d (%s), before the reindex', $i + 2, $shape));
            $this->dropOpenClass();
            self::assertSame($this->found($fuzzphony), $now, sprintf('step %d (%s): the same as the statements of fz / tsv', $i + 2, $shape));
            $fuzzphony->schema()->apply($this->connection); // builds them back for the next step
        }
    }

    /**
     * A rolling deploy: a process that cached the gate (0.12.4, or this release before the apply) keeps sending its lookups to the
     * expression of an index the apply dropped. Statements never name an index, only the expression, so they still run: the same
     * results, only without the index.
     */
    public function testAStatementOfAProcessThatSawTheDroppedIndexStillRuns(): void
    {
        $granted = $this->fuzzphony(self::shapes()['granted non-fuzzy']);
        $granted->schema()->apply($this->connection);
        $granted->reindex(self::NAME);
        $this->connection->execute('ANALYZE ' . self::TABLE);
        $definition = $granted->definition(self::NAME);
        $old = self::oldTrigram($definition, new Names());
        self::assertNotNull($old);
        $this->connection->execute(sprintf('CREATE INDEX %s ON %s %s', $old[0], self::TABLE, $old[1]));
        $expression = (string) preg_replace('/^USING gin \(\((.*)\) "public"\.gin_trgm_ops\)$/', '$1', $old[1]);
        $statement = sprintf("SELECT s.id FROM %s AS s WHERE (%s) OPERATOR(public.<%%) 'ketle' ORDER BY s.id", self::TABLE, $expression);
        $before = $this->connection->fetchAll($statement);
        $reader = $granted->in(self::NAME)->query('ketle red')->thresholds(['typo' => ['mode' => 'always']])->restrictedToFields('name', 'category');
        $results = $reader->get()->ids(); // this process has cached its gate now

        $this->connection->execute('DROP INDEX CONCURRENTLY ' . $old[0]);
        foreach ($this->openIndexes() as $name) {
            $this->connection->execute('DROP INDEX CONCURRENTLY ' . $name); // and the vector one the cached gate trusts
        }

        self::assertSame($before, $this->connection->fetchAll($statement), 'the 0.12.4 lookup on the expression runs without its index');
        self::assertSame($results, $reader->get()->ids(), 'the process with the cached gate runs and finds the same');
        self::assertNotEmpty($results);
    }
}
