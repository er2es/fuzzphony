<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
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
    private function fuzzphony(array $synonyms = [], bool $vocabulary = true): Fuzzphony
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $index = Indexes::products('manual')->withSynonyms(Synonyms::fromEntries($synonyms));
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
        self::assertNull($this->mean($fuzzphony, 'creme'), 'accents are folded on both sides');
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
}
