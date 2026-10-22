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
     * The same documents in the allowed fields for every hidden content: the filler documents and a late-inserted best one
     * (the title is exactly the word), then documents that hold the word only in the hidden fields. A hidden field holds
     * `$hidden` in every document.
     *
     * @param list<string> $hiddenFields
     */
    private function load(string $hidden, array $hiddenFields): void
    {
        $column = static fn(string $name, string $text): string => in_array($name, $hiddenFields, true) ? ':h' : $text;
        $this->connection->execute('DELETE FROM fz_cs_doc');
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc SELECT i, %s, %s, %s, CASE WHEN i %% 2 = 0 THEN 'a' ELSE 'b' END, 1 FROM generate_series(1, 60) AS i", $column('title', "'common filler n' || i"), $column('body', "'about things ' || i"), $column('secret', "'plain'")), ['h' => $hidden]);
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc SELECT i, %s, %s, %s, 'a', 1 FROM generate_series(100, 130) AS i", $column('title', "'unrelated n' || i"), $column('body', "'nothing to see ' || i"), $column('secret', "'plain'")), ['h' => $hidden]);
        $this->connection->execute(sprintf("INSERT INTO fz_cs_doc VALUES (900, %s, %s, %s, 'b', 1)", $column('title', "'common'"), $column('body', "'common common'"), $column('secret', "'plain'")), ['h' => $hidden]);
    }

    /**
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
        $fuzzphony->schema()->apply($this->connection);

        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        $queries = ['common', 'common filler', 'common -unrelated', 'title:common', '"common filler"', 'common | unrelated'];
        $outcomes = [];
        $capped = false;
        foreach (['empty' => '', 'long' => $long, 'the word' => str_repeat('common ', 40)] as $variant => $hidden) {
            $this->load($hidden, array_values(array_diff(['title', 'body', 'secret'], $allowed)));
            $fuzzphony->reindex('cs_docs');
            foreach ($queries as $text) {
                foreach (['never', 'fallback', 'always'] as $mode) {
                    foreach ([[5, 0], [5, 12], [5, 60]] as [$limit, $offset]) {
                        $explained = $fuzzphony->in('cs_docs')->query($text)->thresholds(['fuzzy_mode' => $mode, 'candidate_limit' => 10])
                            ->restrictedToFields(...$allowed)->facets('cat')->limit($limit, $offset)->explain();
                        $result = $explained->result;
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
                        $capped = $capped || $result->totalIsLowerBound;
                    }
                }
            }
            if ($order[0] === 'title' && in_array('title', $allowed, true)) {
                // the late best document (the title is exactly the word) wins although the first candidates the index returns do not hold it
                $best = $fuzzphony->in('cs_docs')->query('common')->thresholds(['fuzzy_mode' => 'never', 'candidate_limit' => 10])->restrictedToFields(...$allowed)->limit(3)->get();
                self::assertSame(900, $best->hits[0]->id, 'the best candidate of a viewer who sees the title is chosen, not the first ones found (' . $variant . ')');
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
            if ($mode === 'always') {
                // the typo branch still selects its candidates unordered (0.9.9): the documents it finds outrank the strict branch's best
                continue;
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
