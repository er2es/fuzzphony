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
 * The candidate selection of a very common word (more matches than the candidate limit: the strict branch then selects the
 * best candidates, not the first ones the index returns) for restricted viewers and tenants.
 *
 * A restricted viewer's selection must not depend on a hidden field in any way: two corpora identical in the allowed fields
 * and different in a hidden one (empty, very long, full of the common word) give identical ids, order, scores, totals,
 * facets and statements. A tenant's selection never returns, and is never crowded out by, another tenant's documents.
 */
final class CandidateSelectionRestrictedTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_cs_doc, fuzzphony_cs_docs, fuzzphony_cs_docs__vocab CASCADE');
        $this->connection->execute('CREATE TABLE fz_cs_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL, tenant_id int NOT NULL)');
    }

    /** @return iterable<string, array{list<string>, list<string>}> index order, the fields the viewer may see */
    public static function layouts(): iterable
    {
        yield 'hidden body first, one allowed field' => [['body', 'title', 'secret'], ['title']];
        yield 'hidden body first, two allowed fields' => [['body', 'title', 'secret'], ['title', 'secret']];
        yield 'hidden body in the middle' => [['title', 'body', 'secret'], ['title', 'secret']];
        yield 'hidden secret last' => [['title', 'body', 'secret'], ['title', 'body']];
        yield 'two hidden fields first' => [['body', 'secret', 'title'], ['title']];
    }

    /**
     * The same documents in the allowed fields for every hidden content: 2 900 filler documents and a late-inserted best one
     * (the title is exactly the word), then 100 documents that hold the word only in the hidden fields (ids from 10 000). A
     * hidden field holds `$hiddenSql` (an expression over the row number `i`: its content and its SIZE vary per document).
     * The source table and the index are created anew for every variant, because the size of the hidden text decides how the
     * rows of the sidecar fill the heap pages, and so the order in which a bitmap heap scan returns them.
     *
     * @param list<string>          $hiddenFields
     * @param array<string, string> $params
     */
    private function load(Fuzzphony $fuzzphony, string $hiddenSql, array $params, array $hiddenFields): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_cs_doc, fuzzphony_cs_docs, fuzzphony_cs_docs__vocab, fuzzphony_cs_docs__next, fuzzphony_cs_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_cs_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, cat text NOT NULL, tenant_id int NOT NULL)');
        $column = static fn(string $name, string $text): string => in_array($name, $hiddenFields, true) ? '(' . $hiddenSql . ')' : $text;
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc SELECT i, %s, %s, %s, CASE WHEN i %% 2 = 0 THEN 'a' ELSE 'b' END, 1 FROM generate_series(1, 2900) AS i", $column('title', "'common filler n' || i"), $column('body', "'about things ' || i"), $column('secret', "'plain'")), $params);
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc SELECT i, %s, %s, %s, 'a', 1 FROM generate_series(10000, 10099) AS i", $column('title', "'unrelated n' || i"), $column('body', "'nothing to see ' || i"), $column('secret', "'plain'")), $params);
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc SELECT 90000, %s, %s, %s, 'b', 1 FROM (SELECT 90000 AS i) AS x", $column('title', "'common'"), $column('body', "'common common'"), $column('secret', "'plain'")), $params);
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('cs_docs');
    }

    /**
     * What a hidden field holds, in size and content, must not change what a restricted viewer finds, exactly: ids, order, scores,
     * total, lower-bound flag, statements and facets (the counts of a capped search too), for `never`, `fallback` and `always`,
     * with words as they are and with typos (`comon` matches 2 900 titles, more than the candidate limit). The strict candidates are
     * the best by the page's own key among the first 100 000 matches (here all 3 000 of them) and the typo-tolerant ones the best by
     * the same key among the matches of the typo branch (here all of them), so the order of the heap, which the size of a hidden
     * field shapes, does not enter.
     *
     * @param list<string> $order
     * @param list<string> $allowed
     */
    #[DataProvider('layouts')]
    public function testNothingAHiddenFieldHoldsChangesTheSelectionOfAViewer(array $order, array $allowed): void
    {
        $builder = IndexDefinition::builder('cs_docs')->fromQuery('SELECT id, title, body, secret, cat FROM fz_cs_doc');
        foreach ($order as $i => $name) {
            $builder = $builder->field($name, ['A', 'B', 'C'][$i], fuzzy: true);
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->filter('cat', 'string')->language('english')->sync('manual')->build()]));

        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        // words as they are (the strict branch, and the typo branch of `always`), and typos (more matches than the candidate limit: `comon` is 2 900 titles)
        $queries = ['common' => ['never', 'fallback', 'always'], 'common filler' => ['never', 'fallback', 'always'], 'title:common' => ['never', 'fallback', 'always'], '"common filler"' => ['never', 'fallback', 'always'], 'comon' => ['fallback', 'always'], 'comon filler' => ['fallback', 'always'], 'title:comon' => ['always']];
        $variants = [
            'empty' => ["''", []],
            'the word, a size per document' => ["CASE WHEN i % 3 = 0 THEN repeat('common ', 50) || i ELSE 'zzz' END", []],
            'sizes 0 to 36 words' => ["repeat('filler words ', i % 37)", []],
            'a very long field in every 50th' => ["CASE WHEN i % 50 = 0 THEN :long ELSE '' END", ['long' => $long]],
        ];
        /** @var array<string, array<string, list<mixed>>> $outcomes the outcome of every search by variant */
        $outcomes = [];
        $capped = false;
        foreach ($variants as $variant => [$hiddenSql, $params]) {
            $this->load($fuzzphony, $hiddenSql, $params, array_values(array_diff(['title', 'body', 'secret'], $allowed)));
            foreach ($queries as $text => $modes) {
                foreach ($modes as $mode) {
                    foreach ([[5, 0], [5, 90]] as [$limit, $offset]) {
                        $explained = $fuzzphony->in('cs_docs')->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 100])
                            ->restrictedToFields(...$allowed)->facets('cat')->limit($limit, $offset)->explain();
                        $result = $explained->result;
                        $capped = $capped || $result->totalIsLowerBound;
                        foreach ($result->ids() as $id) {
                            self::assertTrue($id < 10_000 || $id > 10_099, sprintf('%s | %s | %s: a document that holds the word in a hidden field only', $text, $mode, $variant));
                        }
                        $outcomes[$text . ' | ' . $mode . ' | ' . $offset][$variant] = [
                            array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), round($hit->breakdown->textRank, 9), round($hit->breakdown->fuzzySimilarity, 9)], $result->hits),
                            $result->total,
                            $result->totalIsLowerBound,
                            $result->hasMore(),
                            $result->usedFuzzy,
                            $result->warnings,
                            array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $result->facets),
                            $result->didYouMean,
                            array_map(static fn(array $s): array => [$s['label'], $s['sql'], $s['params']], $explained->statements),
                        ];
                    }
                }
            }
            if ($order[0] === 'title' && in_array('title', $allowed, true)) {
                // the late best document (the title is exactly the word) wins although the first candidates the index returns do not hold it
                $best = $fuzzphony->in('cs_docs')->query('common')->thresholds(['fuzzy_mode' => 'never', 'candidate_limit' => 100])->restrictedToFields(...$allowed)->limit(3)->get();
                self::assertSame(90000, $best->hits[0]->id, 'the best candidate of a viewer who sees the title is chosen, not the first ones found (' . $variant . ')');
            }
        }
        self::assertTrue($capped, 'the test must hit the candidate limit');
        foreach ($outcomes as $case => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'], $outcome, sprintf('%s: the hidden field changed the outcome (%s)', $case, $variant));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'strict only' => ['never'];
        yield 'fallback' => ['fallback'];
        yield 'always' => ['always'];
    }

    private function tenantIndex(string $hiddenOfOne, string $hiddenOfTwo): Fuzzphony
    {
        $this->connection->execute("INSERT INTO fz_cs_doc SELECT i, 'common filler n' || i, 'about things ' || i, :h, 'a', 1 FROM generate_series(1, 60) AS i", ['h' => $hiddenOfOne]);
        // the other tenant: a lot of documents with the word, and a better one than any of the first tenant's
        $this->connection->execute("INSERT INTO fz_cs_doc SELECT i, 'common common filler n' || i, 'common common', :h, 'a', 2 FROM generate_series(1000, 1200) AS i", ['h' => $hiddenOfTwo]);
        $this->connection->execute("INSERT INTO fz_cs_doc VALUES (900, 'common', 'common common', 'x', 'b', 1), (2900, 'common', 'common common common', 'x', 'b', 2)");
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('cs_docs')->fromQuery('SELECT id, title, body, secret, cat, tenant_id FROM fz_cs_doc')
                ->field('title', 'A', fuzzy: true)->field('body', 'B', fuzzy: true)->field('secret', 'C', fuzzy: true)
                ->filter('cat', 'string')->filter('tenant_id', 'int')->tenant('tenant_id')->language('english')->sync('manual')->build(),
        ]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('cs_docs');

        return $fuzzphony;
    }

    #[DataProvider('modes')]
    public function testATenantsSelectionHoldsOnlyItsOwnDocumentsAndFindsItsBest(string $mode): void
    {
        $fuzzphony = $this->tenantIndex('', '');

        foreach ([[1, 900, 61], [2, 2900, 202]] as [$tenant, $best, $matches]) {
            foreach ([0, 15, 60] as $offset) {
                $result = $fuzzphony->in('cs_docs')->forTenant($tenant)->query('common')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 10])->facets('cat')->limit(5, $offset)->get();
                foreach ($result->ids() as $id) {
                    $own = $tenant === 1 ? ($id <= 60 || $id === 900) : $id >= 1000;
                    self::assertTrue($own, sprintf('tenant %d got the document %s of another tenant (offset %d)', $tenant, $id, $offset));
                }
                self::assertLessThanOrEqual($matches, $result->total);
            }
            $first = $fuzzphony->in('cs_docs')->forTenant($tenant)->query('common')->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 10])->limit(3)->get();
            self::assertSame($best, $first->hits[0]->id, sprintf('tenant %d: its own best document is found first', $tenant));
        }
    }

    public function testARestrictedViewerOfATenantKeepsBothScopes(): void
    {
        $fuzzphony = $this->tenantIndex('common', 'common');

        $result = $fuzzphony->in('cs_docs')->forTenant(1)->query('common')->thresholds(['fuzzy_mode' => 'never', 'candidate_limit' => 10])->restrictedToFields('title', 'body')->where('cat', 'b')->limit(5)->get();
        self::assertSame([900], $result->ids());
        $result = $fuzzphony->in('cs_docs')->forTenant(1)->query('common')->thresholds(['fuzzy_mode' => 'never', 'candidate_limit' => 10])->restrictedToFields('title', 'body')->limit(5)->get();
        self::assertSame(900, $result->hits[0]->id);
        foreach ($result->ids() as $id) {
            self::assertTrue($id <= 60 || $id === 900, "only the tenant's own documents");
        }
    }
}
