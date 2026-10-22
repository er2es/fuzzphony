<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A query of stop words with an exclusion ("the who -rock") is searched on the typo-tolerant branch, the exclusion
 * applied. What a restricted viewer finds through it must not depend on a hidden field in any way (its words, its
 * exclusion words, its size): 1 200 documents identical in the allowed fields and different in the hidden ones (empty,
 * full of the searched and the excluded words, of very different sizes) give identical ids, order, scores, totals,
 * facets and statements. Tenants only see their own documents.
 */
final class StopWordExclusionIndependenceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_swx_doc, fuzzphony_swx_docs, fuzzphony_swx_docs__vocab CASCADE');
    }

    /** @return iterable<string, array{list<string>, list<string>}> index order, the fields the viewer may see */
    public static function layouts(): iterable
    {
        yield 'hidden secret last' => [['title', 'body', 'secret'], ['title', 'body']];
        yield 'hidden body first, one allowed field' => [['body', 'title', 'secret'], ['title']];
        yield 'two hidden fields first' => [['body', 'secret', 'title'], ['title']];
    }

    /**
     * @param list<string> $order
     * @param list<string> $allowed
     */
    #[DataProvider('layouts')]
    public function testNothingAHiddenFieldHoldsChangesWhatAViewerFinds(array $order, array $allowed): void
    {
        $hiddenFields = array_values(array_diff(['title', 'body', 'secret'], $allowed));
        $variants = [
            'empty' => "''",
            'the words' => "'the who rock band ' || repeat('the who ', i % 7)",
            'sizes' => "repeat('filler rock text ', 1 + (i * 37) % 30)",
            'long' => "CASE WHEN i % 400 = 0 THEN repeat('zz the who rock ', 5000) ELSE 'zz rock' END",
        ];
        $queries = ['the who -rock', 'the who -band', 'who the -rock -band', 'the who -secret', 'the -rock', '"the who" -rock', 'title:who the -rock', 'the who -rock -the'];
        // below the candidate limit: with a cap the typo-tolerant branch keeps the candidates in the physical order of the sidecar (a known limit, see limitations.md)
        $outcomes = [];
        foreach ($variants as $variant => $hidden) {
            $this->connection->execute('DROP TABLE IF EXISTS fz_swx_doc, fuzzphony_swx_docs, fuzzphony_swx_docs__vocab CASCADE');
            $this->connection->execute('CREATE TABLE fz_swx_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL)');
            $column = static fn(string $name, string $text): string => in_array($name, $hiddenFields, true) ? '(' . $hidden . ')' : $text;
            $this->connection->execute(sprintf(
                "INSERT INTO fz_swx_doc SELECT i, %s, %s, %s, CASE WHEN i %% 2 = 0 THEN 'a' ELSE 'b' END FROM generate_series(1, 1200) AS i",
                $column('title', "CASE i % 5 WHEN 0 THEN 'The Who n' || i WHEN 1 THEN 'Who is it n' || i WHEN 2 THEN 'Rock the who n' || i WHEN 3 THEN 'Band of the day n' || i ELSE 'Other n' || i END"),
                $column('body', "CASE i % 4 WHEN 0 THEN 'the who live ' || i WHEN 1 THEN 'rock band ' || i ELSE 'about ' || i END"),
                $column('secret', "'plain'"),
            ), []);
            $builder = IndexDefinition::builder('swx_docs')->fromQuery('SELECT id, title, body, secret, cat FROM fz_swx_doc');
            foreach ($order as $i => $name) {
                $builder = $builder->field($name, ['A', 'B', 'C'][$i], fuzzy: true);
            }
            $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->filter('cat', 'string')->language('english')->sync('manual')->build()]));
            $fuzzphony->schema()->apply($this->connection);
            $fuzzphony->reindex('swx_docs');
            foreach ($queries as $text) {
                foreach (['fallback', 'always'] as $mode) {
                    foreach ([[5000, false], [5000, true]] as [$limit, $exact]) {
                        $builder = $fuzzphony->in('swx_docs')->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => $limit])
                            ->restrictedToFields(...$allowed)->facets('cat')->limit(8);
                        $explained = ($exact ? $builder->exactCounts() : $builder)->explain();
                        $result = $explained->result;
                        $outcomes[$text . ' | ' . $mode . ' | ' . $limit . ($exact ? ' exact' : '')][$variant] = [
                            array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), round($hit->breakdown->textRank, 9), round($hit->breakdown->fuzzySimilarity, 9)], $result->hits),
                            $result->total,
                            $result->totalIsLowerBound,
                            $result->usedFuzzy,
                            $result->warnings,
                            array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $result->facets),
                            array_map(static fn(array $s): array => [$s['label'], $s['sql'], self::sortedIdLists($s['params'])], $explained->statements),
                        ];
                    }
                }
            }
        }
        self::assertNotEmpty($outcomes['the who -rock | always | 5000']['empty'][0], 'the queries find something');
        foreach ($outcomes as $case => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'], $outcome, sprintf('%s: the hidden fields (%s) changed the outcome', $case, $variant));
            }
        }
    }

    /**
     * The candidate id lists of the typo-tolerant probes are in the physical order of the sidecar (a known limit of the
     * typo-tolerant branch, not of this rule): the set decides what is found, so the lists are compared as sets.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, scalar|null>
     */
    private static function sortedIdLists(array $params): array
    {
        foreach ($params as $name => $value) {
            if (is_string($value) && preg_match('/^\{[0-9,]+\}$/', $value) === 1) {
                $ids = explode(',', trim($value, '{}'));
                sort($ids);
                $params[$name] = '{' . implode(',', $ids) . '}';
            }
        }

        return $params;
    }

    public function testATenantNeverSeesAnotherTenantsDocuments(): void
    {
        $this->connection->execute('CREATE TABLE fz_swx_doc (id bigint PRIMARY KEY, title text NOT NULL, owner bigint NOT NULL)');
        $this->connection->execute("INSERT INTO fz_swx_doc SELECT i, CASE WHEN i % 3 = 0 THEN 'The Who rock' ELSE 'The Who n' || i END, 1 + i % 2 FROM generate_series(1, 400) AS i");
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('swx_docs')->fromQuery('SELECT id, title, owner FROM fz_swx_doc')->field('title', 'A', fuzzy: true)->filter('owner', 'int')->tenant('owner')->sync('manual')->build(),
        ]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('swx_docs');

        foreach ([1, 2] as $tenant) {
            $ids = $fuzzphony->in('swx_docs')->query('the who -rock')->forTenant($tenant)->limit(500)->thresholds(['candidate_limit' => 1000])->get()->ids();
            self::assertNotSame([], $ids);
            foreach ($ids as $id) {
                $id = (int) $id;
                self::assertSame($tenant, 1 + $id % 2, 'the document belongs to the tenant');
                self::assertNotSame(0, $id % 3, 'the excluded word is applied');
            }
        }
    }
}
