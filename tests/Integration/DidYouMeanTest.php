<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** "Did you mean": a low-hit search with a whole word the vocabulary does not have suggests the nearest word it has. */
final class DidYouMeanTest extends TestCase
{
    private Connection $connection;

    /** @param array<array-key, mixed> $synonyms */
    private function fuzzphony(array $synonyms = [], bool $vocabulary = true, bool $tenant = false): Fuzzphony
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $index = Indexes::products('manual', tenant: $tenant)->withSynonyms(Synonyms::fromEntries($synonyms));
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
        if (!$vocabulary) {
            $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
        }

        return $fuzzphony;
    }

    private function mean(Fuzzphony $fuzzphony, string $query, string $thresholds = ''): ?string
    {
        $builder = $fuzzphony->in('products');
        if ($thresholds !== '') {
            $builder = $builder->thresholds(['fallback_below' => (int) $thresholds]);
        }

        return $builder->query($query)->get()->didYouMean;
    }

    public function testAMisspelledWordIsReplacedByTheWordTheIndexHas(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame('headphones', $this->mean($fuzzphony, 'hedphones'));
        self::assertSame('wireless mouse', $this->mean($fuzzphony, 'wireles mouse'), 'found by typo tolerance, still suggested');
        self::assertSame('wireless', $this->mean($fuzzphony, 'Wireles'), 'the case of the typed word is not kept: the suggestion is the index word');
    }

    public function testOperatorsQuotesFieldsAndPrefixesStayWhereTheyWere(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame('wireless mouse -cable', $this->mean($fuzzphony, 'wireles mouse -cable'));
        self::assertSame('"wireless headphones"', $this->mean($fuzzphony, '"wireles headphones"'));
        self::assertSame('brand:logitech', $this->mean($fuzzphony, 'brand:logitec'));
        self::assertSame('headphones | mouse', $this->mean($fuzzphony, 'hedphones | mouse'));
        self::assertSame('wireless -headphones', $this->mean($fuzzphony, 'wireles -headphones'));
        self::assertNull($this->mean($fuzzphony, 'wirele*'), 'a prefix is not a whole word');
        self::assertNull($this->mean($fuzzphony, 'mouse -cabel'), 'an excluded word is not corrected');
    }

    public function testNothingIsSuggestedForKnownWordsStopWordsOrAnythingFarAway(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertNull($this->mean($fuzzphony, 'mouse'), 'a word of the vocabulary');
        self::assertNull($this->mean($fuzzphony, 'creme'), 'a word of the vocabulary');
        self::assertNull($this->mean($fuzzphony, 'crème'), 'accents are folded on the typed side too');
        self::assertNull($this->mean($fuzzphony, 'mouse for'), 'a stop word is not a word to correct');
        self::assertNull($this->mean($fuzzphony, 'qqqqzzzz'), 'nothing near');
        self::assertNull($this->mean($fuzzphony, 'mxxse'), 'two edits away from a five-letter word is too far');
        self::assertNull($this->mean($fuzzphony, ''), 'a browse has no word');
        self::assertNull($this->mean($fuzzphony, 'wireless mouse'));
    }

    public function testASearchWithEnoughHitsIsNotSecondGuessed(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame('wireless', $this->mean($fuzzphony, 'wireles'));
        self::assertNull($this->mean($fuzzphony, 'wireles', '1'), 'fallback_below 1: one hit is enough');
        self::assertNull($fuzzphony->in('products')->query('hedphones')->thresholds(['did_you_mean' => false])->get()->didYouMean);
    }

    public function testNearerWinsThenTheWordInMoreDocuments(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" VALUES ('moose', 1), ('house', 100)");
        self::assertSame('mouse', $this->mean($fuzzphony, 'mose'), 'mouse is in three documents, moose in one; house is two edits away');

        $this->connection->execute("UPDATE \"fuzzphony_products__vocab\" SET freq = 50 WHERE word = 'moose'");
        self::assertSame('moose', $this->mean($fuzzphony, 'mose'), 'as near as mouse and in more documents');
    }

    public function testAWordAnExpandedSynonymCoversIsNotCorrected(): void
    {
        self::assertSame('headphones', $this->mean($this->fuzzphony(), 'headfones'));
        self::assertNull($this->mean($this->fuzzphony([['headfones', 'headphones']]), 'headfones'), 'the index knows it by definition');
    }

    public function testNoVocabularyMeansNoSuggestionAndNoError(): void
    {
        self::assertNull($this->mean($this->fuzzphony(vocabulary: false), 'hedphones'), 'an empty vocabulary');

        $fuzzphony = $this->fuzzphony();
        $this->connection->execute('DROP TABLE "fuzzphony_products__vocab"');
        self::assertNull($this->mean($fuzzphony, 'hedphones'), 'a table the schema has not created yet');
    }

    public function testTheDistanceAllowedGrowsWithTheLengthOfTheWord(): void
    {
        $fuzzphony = $this->fuzzphony();
        $mean = function (string $query, string ...$vocabulary) use ($fuzzphony): ?string {
            $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
            foreach ($vocabulary as $word) {
                $this->connection->execute('INSERT INTO "fuzzphony_products__vocab" VALUES (:word, 1)', ['word' => $word]);
            }

            return $this->mean($fuzzphony, $query);
        };

        // a third of the letters, at least one edit
        self::assertSame('abcdxy', $mean('abcdef', 'abcdxy'), 'six letters: two edits are fine');
        self::assertNull($mean('abcdef', 'abcdxyz'), 'six letters: three edits are too far');
        self::assertSame('abcdx', $mean('abcde', 'abcdx'), 'five letters: one edit is fine');
        self::assertNull($mean('abcde', 'abcxx'), 'five letters: two edits are too far');
        self::assertSame('abx', $mean('abc', 'abx'), 'three letters: one edit');
        self::assertNull($mean('abc', 'abcxx'), 'three letters: two edits are too far');
        self::assertNull($mean('zzzzzz', 'abcdef'));
    }

    public function testWordsOutsideAsciiAreComparedByCharactersNotBytes(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" VALUES ('кошка', 1), ('кружка', 1)");

        // one character differs, which is two bytes: a five-letter word may be one edit away
        self::assertSame('кошка', $this->mean($fuzzphony, 'кошкв'));
        self::assertSame('кошка', $this->mean($fuzzphony, 'КОШКВ'), 'upper case is folded');
    }

    public function testTheDoctorHasNoVocabularyCheckForAnIndexWithoutFuzzyFields(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $index = Indexes::products('manual')->withFields([new FieldDefinition('name', Weight::A)]);
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);

        self::assertNotContains('Vocabulary', array_map(static fn(Check $check): string => $check->name, $fuzzphony->inspect('products')->checks));
    }

    public function testATenantScopedIndexNeverSuggestsAnything(): void
    {
        $fuzzphony = $this->fuzzphony(tenant: true);
        $search = $fuzzphony->in('products')->forTenant(1);

        // the vocabulary is the words of every tenant: a suggestion, or its absence, would tell one customer what another has
        self::assertNull($search->query('hedphones')->get()->didYouMean);
        self::assertNull($search->query('wireles mouse')->get()->didYouMean);
        self::assertSame('headphones', $this->fuzzphony()->in('products')->query('hedphones')->get()->didYouMean, 'the same query on an index without a tenant');
    }

    public function testASearchRoleThatMayNotReadTheVocabularyStillSearches(): void
    {
        $fuzzphony = $this->fuzzphony();
        $role = 'fz_reader_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $this->connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fuzzphony_products TO %s', $role));
            $this->connection->execute(sprintf('SET ROLE %s', $role));
            try {
                $result = $fuzzphony->in('products')->query('mouse')->get();
                $typo = $fuzzphony->in('products')->query('hedphones')->get();
            } finally {
                $this->connection->execute('RESET ROLE');
            }
        } finally {
            $this->connection->execute(sprintf('DROP OWNED BY %s', $role));
            $this->connection->execute(sprintf('DROP ROLE %s', $role));
        }

        self::assertCount(3, $result->hits, 'three hits, fewer than fallback_below: the suggestion lookup ran and did not fail the search');
        self::assertNull($result->didYouMean);
        self::assertNull($typo->didYouMean, 'no privilege, no suggestion, no error');
        self::assertNotEmpty($typo->hits);
    }

    public function testAWordTheIndexFindsIsNeverCorrected(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Blade', 'Razor sharp steel', 1, 1000, true, 0, now()), (7, 'Wi-Fi router', 'Dual band', 1, 1000, true, 0, now())");
        $fuzzphony->refresh('products', [6, 7]);

        // not in the vocabulary (the description is not typo-tolerant, "wi" and "fi" are too short), but the index finds them
        self::assertNull($this->mean($fuzzphony, 'razor'), 'a word of a field that is not typo-tolerant, next to the vocabulary word "razer"');
        self::assertNull($this->mean($fuzzphony, 'description:razor'));
        self::assertNull($this->mean($fuzzphony, 'wi-fi'));
        self::assertNull($this->mean($fuzzphony, 'headphone'), 'an inflection of a word of the index');
        self::assertNull($this->mean($fuzzphony, 'steel'));
    }

    public function testCodesWithDigitsAreNotCorrected(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" VALUES ('rtx4080', 5)");

        self::assertNull($this->mean($fuzzphony, 'rtx4090'));
    }

    public function testTheIndexsMinimumLengthDecidesNotAQueryThatLowersIt(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" VALUES ('abc', 1)");

        self::assertSame('abc', $this->mean($fuzzphony, 'abx'), 'three letters is the index minimum');
        self::assertNull($fuzzphony->in('products')->query('ab')->thresholds(['fuzzy_min_length' => 1])->get()->didYouMean, 'shorter words were never put in the vocabulary');
    }

    public function testASessionSimilarityThresholdDoesNotChangeTheCandidates(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute("SELECT set_config('pg_trgm.similarity_threshold', '0.9', false)");
        try {
            self::assertSame('headphones', $this->mean($fuzzphony, 'hedphones'));
            self::assertSame('0.9', $this->connection->fetchValue("SELECT current_setting('pg_trgm.similarity_threshold')"), "the caller's setting is left as it was");
        } finally {
            $this->connection->execute("SELECT set_config('pg_trgm.similarity_threshold', '0.3', false)");
        }
    }
}
