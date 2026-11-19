<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** SearchBuilder::kinds(): a plain predicate on the kind of a multi-source index, in hits, totals, facets and the federated search. */
final class MultiSourceKindsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_kinds, fuzzphony_kinds__vocab, fuzzphony_kinds__next, fuzzphony_kinds__changes, ms_k_one, ms_k_two, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
        $this->connection->execute('CREATE TABLE ms_k_one (id bigint PRIMARY KEY, title text NOT NULL, secret text NOT NULL, cat text NOT NULL)');
        $this->connection->execute('CREATE TABLE ms_k_two (id bigint PRIMARY KEY, title text NOT NULL, secret text NOT NULL, cat text NOT NULL)');
        $this->connection->execute("INSERT INTO ms_k_one VALUES (1, 'alpha mouse', 'zzz', 'a'), (2, 'beta mouse', 'zzz', 'b'), (3, 'gamma', 'mouse', 'a')");
        $this->connection->execute("INSERT INTO ms_k_two VALUES (1, 'alpha mouse', 'zzz', 'b'), (2, 'delta mouse', 'zzz', 'b')");
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_kinds, fuzzphony_kinds__vocab, fuzzphony_kinds__next, fuzzphony_kinds__changes, ms_k_one, ms_k_two CASCADE');
    }

    private function fuzzphony(): Fuzzphony
    {
        $index = IndexDefinition::builder('kinds')
            ->source('one', 'SELECT id, title, secret, cat FROM ms_k_one', watch: [new Watch('ms_k_one')])
            ->source('two', 'SELECT id, title, secret, cat FROM ms_k_two', watch: [new Watch('ms_k_two')])
            ->field('title', 'A', fuzzy: true)
            ->field('secret', 'C', granted: 'ROLE_SECRET')
            ->filter('cat', 'string')
            ->sync('manual')
            ->build();
        $single = IndexDefinition::builder('solo')->fromTable('ms_k_one')->field('title', 'A')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index, $single]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('kinds');

        return $fuzzphony;
    }

    /**
     * @param list<Hit> $hits
     *
     * @return list<string>
     */
    private static function keys(array $hits): array
    {
        $keys = array_map(static fn(Hit $h): string => $h->kind . ':' . $h->id, $hits);
        sort($keys);

        return $keys;
    }

    public function testHitsTotalAndFacetsCountOnlyTheNamedKinds(): void
    {
        $f = $this->fuzzphony();

        $one = $f->in('kinds')->query('mouse')->kinds('one')->facets('cat')->get();
        self::assertSame(['one:1', 'one:2', 'one:3'], self::keys($one->hits));
        self::assertSame(3, $one->total);
        $counts = [];
        foreach ($one->facets['cat'] as $v) {
            $counts[(string) $v->value] = $v->count;
        }
        self::assertSame(['a' => 2, 'b' => 1], $counts);

        $both = $f->in('kinds')->query('mouse')->kinds('one', 'two')->get();
        self::assertSame(5, $both->total);
        self::assertSame(['two:1', 'two:2'], self::keys($f->in('kinds')->query('')->kinds('two')->get()->hits), 'browse');
        self::assertSame(['two:1'], self::keys($f->in('kinds')->query('mouse')->kinds('two')->where('cat', 'b')->whereIn('cat', ['b'])->limit(1)->get()->hits), 'composes with filters');
        self::assertSame(2, $f->in('kinds')->query('mouse')->kinds('two')->exactCounts()->get()->total);
    }

    public function testRestrictedViewerGetsTheSameAsTheUnrestrictedSearchFilteredAfterwards(): void
    {
        $f = $this->fuzzphony();

        foreach (['mouse', 'alpha', 'zzz', 'mose', ''] as $text) {
            $all = $f->in('kinds')->query($text)->restrictedToFields('title')->limit(100)->get();
            $only = $f->in('kinds')->query($text)->restrictedToFields('title')->kinds('two')->limit(100)->get();
            $expected = array_values(array_filter($all->hits, static fn(Hit $h): bool => $h->kind === 'two'));
            self::assertSame(self::keys($expected), self::keys($only->hits), $text);
            self::assertSame(array_map(static fn(Hit $h): string => $h->kind . ':' . $h->id, $expected), array_map(static fn(Hit $h): string => $h->kind . ':' . $h->id, $only->hits), 'same order: ' . $text);
        }
    }

    public function testFederatedSearchTakesKindsInItsConfigure(): void
    {
        $f = $this->fuzzphony();

        $result = $f->federated()->index('kinds', configure: static fn($b) => $b->kinds('two'))->query('mouse')->get();

        self::assertSame(2, count($result->hits));
    }

    public function testRefusals(): void
    {
        $f = $this->fuzzphony();
        try {
            $f->in('kinds')->kinds('three');
            self::fail('unknown kind');
        } catch (InvalidQuery $e) {
            self::assertStringContainsString('Known kinds: one, two', $e->getMessage());
        }
        try {
            $f->in('solo')->kinds('one');
            self::fail('single source');
        } catch (InvalidQuery $e) {
            self::assertStringContainsString('single source', $e->getMessage());
        }
        $this->expectException(InvalidQuery::class);
        $f->in('kinds')->kinds();
    }

    public function testASearchWithoutKindsHasTheSameStatements(): void
    {
        $f = $this->fuzzphony();
        $plain = $f->in('kinds')->query('mouse')->facets('cat')->explain();
        $withAll = $f->in('kinds')->query('mouse')->facets('cat')->kinds('one', 'two')->explain();

        self::assertStringNotContainsString('s.kind', json_encode($plain->statements, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('s.kind = ANY(CAST(', json_encode($withAll->statements, JSON_THROW_ON_ERROR));
        self::assertSame(count($plain->statements), count($withAll->statements));
    }
}
