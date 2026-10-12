<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A viewer who may not see a field: its content must not influence a match, a score, a bonus, a highlight, a count
 * or a suggestion. "secret" is the hidden field; every restricted search may see "title" and "body" only.
 */
final class FieldAuthorizationTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_auth_doc, fuzzphony_auth_docs, fuzzphony_auth_docs__vocab, fuzzphony_auth_docs__next, fuzzphony_auth_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_auth_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, in_stock boolean NOT NULL)');
        $rows = [
            [1, 'Plain notebook', 'lined pages', 'margin zebrafish', true],
            [2, 'Zebrafish poster', 'an aquarium', '', true],
            [3, 'Desk lamp', 'bright', 'zebrafish zebrafish zebrafish lamp', true],
            [4, 'Desk lamp', 'bright', 'plain', false],
            [5, 'Desk lamp', 'desk lamp', '', false],
        ];
        foreach ($rows as $row) {
            $this->insert(...$row);
        }
        $this->fuzzphony = $this->build();
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('auth_docs');
    }

    private function build(?SearchGate $gate = null): Fuzzphony
    {
        $index = IndexDefinition::builder('auth_docs')
            ->fromQuery("SELECT id, title, body, secret, in_stock, '' AS note FROM fz_auth_doc")
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'B')
            ->field('secret', 'C', fuzzy: true)
            ->field('note', 'D', highlight: false)
            ->filter('in_stock', 'bool')
            ->language('english')
            ->sync('manual')
            ->build()
            ->withSynonyms(Synonyms::fromEntries([['border', 'margin']]));

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]), $gate);
    }

    private function insert(int $id, string $title, string $body, string $secret, bool $inStock): void
    {
        $this->connection->execute('INSERT INTO fz_auth_doc VALUES (:id, :title, :body, :secret, :stock)', ['id' => $id, 'title' => $title, 'body' => $body, 'secret' => $secret, 'stock' => $inStock]);
    }

    public function testAWordOnlyInAHiddenFieldFindsNothing(): void
    {
        self::assertEqualsCanonicalizing([1, 2, 3], $this->search('zebrafish')->get()->ids());
        $restricted = $this->restricted('zebrafish')->get();
        self::assertSame([2], $restricted->ids());
        self::assertSame(1, $restricted->total);
        self::assertSame([2], $this->restricted('zebrafish', 'always')->get()->ids(), 'with typo tolerance too');
        self::assertSame([2], $this->restricted('zebraf*')->get()->ids(), 'a prefix');
    }

    public function testTheScoreIgnoresTheHiddenField(): void
    {
        $unrestricted = self::byId($this->search('lamp')->get()->hits);
        self::assertGreaterThan($unrestricted[4]->score, $unrestricted[3]->score, 'unrestricted, the hidden "lamp" of 3 counts');

        $result = $this->restricted('lamp')->get();
        $hits = self::byId($result->hits);
        self::assertSame($hits[4]->score, $hits[3]->score);
        self::assertEquals($hits[4]->breakdown, $hits[3]->breakdown);
        self::assertSame([3, 4], array_values(array_filter($result->ids(), static fn(int|string $id): bool => $id !== 5)), 'equal scores: by id');

        $fuzzy = self::byId($this->restricted('lamp', 'always')->get()->hits);
        self::assertSame($fuzzy[4]->score, $fuzzy[3]->score, 'with the typo-tolerant branch too');
    }

    public function testAFieldScopedWordOnAHiddenFieldFindsNothing(): void
    {
        self::assertEqualsCanonicalizing([1, 3], $this->search('secret:zebrafish')->get()->ids());
        self::assertSame([], $this->restricted('secret:zebrafish')->get()->ids());
        self::assertSame([], $this->restricted('secret:zebrafsh', 'always')->get()->ids());
        self::assertSame([2], $this->restricted('title:zebrafish')->get()->ids());
        self::assertSame([2], $this->restricted('colour:zebrafish')->get()->ids(), 'an unknown field searches every field the viewer may see');
    }

    public function testATypoOfAHiddenWordIsNotTolerated(): void
    {
        self::assertEqualsCanonicalizing([1, 2, 3], $this->search('zebrafsh', 'always')->get()->ids());
        self::assertSame([2], $this->restricted('zebrafsh', 'always')->get()->ids());
        self::assertSame([2], $this->restricted('zebrafsh', 'fallback')->get()->ids());
    }

    public function testAnExcludedWordInAHiddenFieldExcludesNothing(): void
    {
        self::assertSame([3, 5], self::sorted($this->search('lamp -plain', 'never')->get()->ids()), 'unrestricted, 4 has "plain" in its secret');
        self::assertSame([3, 4, 5], self::sorted($this->restricted('lamp -plain', 'never')->get()->ids()));
        self::assertSame([3, 4, 5], self::sorted($this->restricted('lamp -plain', 'always')->get()->ids()));
        // NOT (notebook OR NOT plain): a lamp with "plain"; only 4 has it, in its hidden field
        self::assertSame([4], $this->search('lamp -(notebook | -plain)', 'never')->get()->ids());
        self::assertSame([], $this->restricted('lamp -(notebook | -plain)', 'never')->get()->ids());
        self::assertSame([], $this->restricted('lamp -(notebook | -plain)', 'always')->get()->ids());
    }

    public function testAnOrWithAnExcludedWordReadsOnlyTheAllowedFields(): void
    {
        // a lamp with pages OR without zebrafish: 3 has zebrafish only in its hidden field
        self::assertSame([4, 5], self::sorted($this->search('lamp (pages | -zebrafish)', 'never')->get()->ids()));
        self::assertSame([3, 4, 5], self::sorted($this->restricted('lamp (pages | -zebrafish)', 'never')->get()->ids()));
        self::assertSame([3, 4, 5], self::sorted($this->restricted('lamp (pages | -zebrafish)', 'always')->get()->ids()));
    }

    public function testAPhraseNeverSpansIntoAHiddenField(): void
    {
        self::assertSame([1], $this->search('"pages margin"', 'never')->get()->ids(), 'unrestricted, body and secret are adjacent in the whole vector');
        self::assertSame([], $this->restricted('"pages margin"', 'never')->get()->ids());
        self::assertSame([], $this->restricted('"pages margin"', 'always')->get()->ids());
    }

    public function testASynonymNeverMatchesAHiddenField(): void
    {
        self::assertSame([1], $this->search('border')->get()->ids(), 'border expands to margin, in the secret of 1');
        self::assertSame([], $this->restricted('border')->get()->ids());
        self::assertSame([], $this->restricted('border', 'always')->get()->ids());
    }

    public function testTheRelaxationDoesNotKeepAWordForItsHiddenMatches(): void
    {
        self::assertSame([3], $this->search('zebrafish lamp', 'never')->get()->ids());
        // both words match something the viewer may see (2, and the lamps), never together: nothing to relax, nothing found
        self::assertSame([], $this->restricted('zebrafish lamp', 'never')->get()->ids());
        self::assertSame([], $this->restricted('secret:lamp secret:zebrafish', 'never')->get()->ids(), 'every word on the hidden field');
        $relaxed = $this->restricted('margin lamp', 'never')->get();
        self::assertSame([3, 4, 5], self::sorted($relaxed->ids()), '"margin" matches nothing visible: it is ignored, as for a word no document has');
    }

    public function testTheExactBonusIsZeroWhenThePrimaryFieldIsHidden(): void
    {
        $unrestricted = self::byId($this->search('Desk lamp')->get()->hits);
        self::assertGreaterThan(0.0, $unrestricted[5]->breakdown->exactBonus);

        $hits = $this->search('Desk lamp')->restrictedToFields('body')->get()->hits;
        self::assertSame([5], array_map(static fn(Hit $h): int|string => $h->id, $hits));
        self::assertSame(0.0, $hits[0]->breakdown->exactBonus);
        self::assertSame(0.0, $hits[0]->breakdown->prefixBonus);
        $prefix = $this->search('Desk')->restrictedToFields('body')->get()->hits;
        self::assertSame(0.0, $prefix[0]->breakdown->prefixBonus ?? null);
    }

    public function testHighlightsNeverShowAHiddenField(): void
    {
        $result = $this->restricted('lamp')->highlight('title', 'secret')->get();

        self::assertNotSame([], $result->hits);
        foreach ($result->hits as $hit) {
            self::assertArrayHasKey('title', $hit->highlights);
            self::assertArrayNotHasKey('secret', $hit->highlights);
        }
        $none = $this->restricted('lamp')->highlight('secret')->get();
        foreach ($none->hits as $hit) {
            self::assertSame([], $hit->highlights);
        }
    }

    public function testAHighlightRequestFailsOrPassesTheSameForEveryViewer(): void
    {
        // "note" is not highlightable, "nosuch" is no field; the restricted viewer may see neither, and "fridge" finds nothing
        foreach (['note', 'nosuch'] as $field) {
            foreach (['lamp', 'fridge'] as $text) {
                foreach ([$this->search($text), $this->restricted($text), $this->search($text)->restrictedToFields()] as $i => $builder) {
                    try {
                        $builder->highlight('title', $field)->get();
                        self::fail(sprintf('highlight(%s) of "%s" passed for viewer %d', $field, $text, $i));
                    } catch (InvalidQuery $e) {
                        self::assertSame(sprintf('Cannot highlight "%s": it is not a highlightable field of index "auth_docs".', $field), $e->getMessage());
                    }
                }
            }
        }
    }

    public function testSuggestionsAndDidYouMeanAreOffForARestrictedViewer(): void
    {
        self::assertSame('zebrafish', $this->search('zebrafsh')->thresholds(['did_you_mean' => true])->get()->didYouMean);
        self::assertNull($this->search('zebrafsh')->thresholds(['did_you_mean' => true])->restrictedToFields('title', 'body')->get()->didYouMean);
        self::assertNull($this->search('margn')->thresholds(['did_you_mean' => true])->restrictedToFields('title', 'body')->get()->didYouMean);
    }

    public function testTheTotalAndTheFacetsCountOnlyWhatTheViewerMatches(): void
    {
        $unrestricted = $this->search('zebrafish')->facets('in_stock')->get();
        self::assertSame(3, $unrestricted->total);
        self::assertSame(3, $unrestricted->facets['in_stock'][0]->count);

        $restricted = $this->restricted('zebrafish')->facets('in_stock')->get();
        self::assertSame(1, $restricted->total);
        self::assertCount(1, $restricted->facets['in_stock']);
        self::assertSame(1, $restricted->facets['in_stock'][0]->count);
    }

    public function testTheExactCountsCountOnlyWhatTheViewerMatches(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->insert(100 + $i, 'okapi', 'x', '', true);
            $this->insert(200 + $i, 'giraffe', 'x', 'okapi', true);
        }
        $this->fuzzphony->reindex('auth_docs');

        $unrestricted = $this->search('okapi', 'never')->thresholds(['candidate_limit' => 10])->exactCounts()->facets('in_stock')->get();
        self::assertSame(24, $unrestricted->total);
        self::assertSame(24, $unrestricted->facets['in_stock'][0]->count);
        $restricted = $this->restricted('okapi', 'never')->thresholds(['candidate_limit' => 10])->exactCounts()->facets('in_stock')->get();
        self::assertSame(12, $restricted->total);
        self::assertFalse($restricted->totalIsLowerBound);
        self::assertSame(12, $restricted->facets['in_stock'][0]->count);
    }

    public function testNoFieldAtAllMatchesNothing(): void
    {
        foreach (['zebrafish', 'lamp', 'desk lamp', 'zebrafsh', 'title:lamp', 'secret:lamp', 'lamp (pages | -zebrafish)'] as $text) {
            foreach (['never', 'fallback', 'always'] as $mode) {
                $result = $this->search($text, $mode)->restrictedToFields()->get();
                self::assertSame([], $result->ids(), $text . ' / ' . $mode);
                self::assertSame(0, $result->total);
            }
        }
        $browse = $this->search('')->restrictedToFields()->get();
        self::assertSame([1, 2, 3, 4, 5], self::sorted($browse->ids()), 'browsing has no text to restrict');
    }

    public function testExplainShowsNoHiddenColumnAndNoPlan(): void
    {
        foreach (['zebrafish', 'zebrafsh', 'lamp -plain', 'title:lamp'] as $text) {
            $explanation = $this->restricted($text, 'always')->explain(true);
            self::assertNotSame([], $explanation->statements);
            foreach ($explanation->statements as $statement) {
                self::assertDoesNotMatchRegularExpression('/[tz]_secret|secret/', $statement['sql'], $statement['label']);
            }
            // the planner's estimates and EXPLAIN ANALYZE's row counts are read from the whole vector (every field)
            self::assertCount(1, $explanation->plan);
            self::assertStringContainsString('restricted', $explanation->plan[0]);
        }
        self::assertNotSame([], $this->search('zebrafish')->explain()->plan);
    }

    public function testAnUnrestrictedSearchIsUnchanged(): void
    {
        $everything = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return Access::all();
            }
        };
        $plain = $this->fuzzphony;
        $gated = $this->build(new SearchGate());
        $guarded = $this->build(new SearchGate(guards: [$everything]));
        foreach (['zebrafish', 'zebrafsh', 'lamp -plain', 'title:lamp | border', '"pages margin"'] as $text) {
            $expected = $plain->in('auth_docs')->query($text)->facets('in_stock')->highlight('title', 'secret')->explain()->statements;
            self::assertSame($expected, $gated->in('auth_docs')->query($text)->facets('in_stock')->highlight('title', 'secret')->explain()->statements, $text);
            self::assertSame($expected, $guarded->in('auth_docs')->query($text)->facets('in_stock')->highlight('title', 'secret')->asActor(new Actor(user: 'ada'))->explain()->statements, $text);
        }
    }

    public function testAGuardRestrictsTheFieldsOfAnActor(): void
    {
        $titleAndBody = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return Access::only(['title', 'body']);
            }
        };
        $gated = $this->build(new SearchGate(guards: [$titleAndBody]));

        self::assertSame([2], $gated->in('auth_docs')->query('zebrafish')->asActor(new Actor(user: 'ada'))->get()->ids());
        self::assertEqualsCanonicalizing([1, 2, 3], $gated->in('auth_docs')->query('zebrafish')->get()->ids(), 'no actor, no guard');
    }

    private function search(string $text, string $mode = 'fallback'): SearchBuilder
    {
        return $this->fuzzphony->in('auth_docs')->query($text)->thresholds(['fuzzy_mode' => $mode]);
    }

    private function restricted(string $text, string $mode = 'fallback'): SearchBuilder
    {
        return $this->search($text, $mode)->restrictedToFields('title', 'body');
    }

    /**
     * @param list<Hit> $hits
     *
     * @return array<int|string, Hit>
     */
    private static function byId(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[$hit->id] = $hit;
        }

        return $out;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int|string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
