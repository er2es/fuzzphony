<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The vocabulary of a fuzzy index: created by the schema, filled by a full reindex, never per write. */
final class VocabularyTest extends TestCase
{
    private \Fuzzphony\Core\Database\Connection $connection;

    private function fuzzphony(): Fuzzphony
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([Indexes::products('manual')]));
        $fuzzphony->schema()->apply($this->connection);

        return $fuzzphony;
    }

    /**
     * @phpstan-impure
     *
     * @return array<string, int>
     */
    private function words(): array
    {
        $words = [];
        foreach ($this->connection->fetchAll('SELECT word, freq FROM "fuzzphony_products__vocab"') as $row) {
            $words[Coerce::str($row['word'])] = Coerce::int($row['freq']);
        }
        ksort($words);

        return $words;
    }

    public function testTheSchemaCreatesAnEmptyVocabularyAndAFullReindexFillsIt(): void
    {
        $fuzzphony = $this->fuzzphony();
        self::assertSame([], $this->words());

        $result = $fuzzphony->reindex('products');

        $words = $this->words();
        self::assertSame(count($words), $result->vocabulary);
        self::assertNull($result->vocabularyError);
        // the typo-tolerant text is the name and the brand, normalised: in how many documents each word occurs
        self::assertSame(3, $words['mouse']);
        self::assertSame(2, $words['wireless']);
        self::assertSame(2, $words['logitech']);
        self::assertSame(1, $words['creme'], 'accents are folded');
        self::assertSame(1, $words['headphones']);
        self::assertSame(1, $words['rgb'], 'three letters are fuzzy_min_length');
        self::assertSame([], array_filter(array_keys($words), static fn(string $w): bool => mb_strlen($w) < 3), 'nothing shorter than fuzzy_min_length');
        self::assertArrayNotHasKey('silent', $words, 'only the typo-tolerant fields: the description is not part of it');
    }

    public function testTheVocabularyIsRebuiltNotAppendedAndFollowsTheDocuments(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->reindex('products');
        $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Zebra lamp', 'Striped', 1, 1000, true, 0, now())");
        $fuzzphony->refresh('products', [6]);
        self::assertArrayNotHasKey('zebra', $this->words(), 'a write does not touch the vocabulary');

        $first = $fuzzphony->reindex('products')->vocabulary;
        $second = $fuzzphony->reindex('products')->vocabulary;

        self::assertSame($first, $second, 'rebuilt, not appended');
        self::assertSame(1, $this->words()['zebra']);
        $this->connection->execute('DELETE FROM fz_product WHERE id = 6');
        $fuzzphony->reindex('products');
        self::assertArrayNotHasKey('zebra', $this->words(), 'a word of a removed document is gone');
    }

    public function testInPlaceAndVocabularyOnlyRunsRebuildItToo(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertGreaterThan(0, $fuzzphony->reindex('products', new ReindexOptions(inPlace: true))->vocabulary);
        $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');

        $only = $fuzzphony->rebuildVocabulary('products');
        self::assertSame(count($this->words()), $only);
        self::assertSame(3, $this->words()['mouse']);
    }

    public function testAResumedRunAndAnOptOutLeaveTheVocabularyAlone(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertNull($fuzzphony->reindex('products', new ReindexOptions(vocabulary: false))->vocabulary);
        self::assertSame([], $this->words());
        self::assertNull($fuzzphony->reindex('products', new ReindexOptions(resumeAfter: 2))->vocabulary);
        self::assertSame([], $this->words());
    }

    public function testAMissingVocabularyTableDoesNotUndoTheReindexButIsReported(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->connection->execute('DROP TABLE "fuzzphony_products__vocab"');

        $result = $fuzzphony->reindex('products');

        self::assertSame(5, $result->written);
        self::assertNull($result->vocabulary);
        self::assertStringContainsString('fuzzphony:schema --apply', (string) $result->vocabularyError);
        $this->expectException(\Fuzzphony\Core\Exception\EngineFailure::class);
        $fuzzphony->rebuildVocabulary('products');
    }

    public function testAnIndexWithoutFuzzyFieldsHasNoVocabulary(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $index = Indexes::products('manual')->withFields([new \Fuzzphony\Core\Definition\FieldDefinition('name', \Fuzzphony\Core\Definition\Weight::A)]);
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($connection);

        self::assertNull($fuzzphony->reindex('products')->vocabulary);
        self::assertNull($connection->fetchValue("SELECT to_regclass('fuzzphony_products__vocab')"));
        $this->expectException(InvalidArgument::class);
        $fuzzphony->rebuildVocabulary('products');
    }

    public function testDroppingTheIndexDropsItsVocabulary(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->reindex('products');
        self::assertNotNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__vocab')"));

        $fuzzphony->engine()->dropSchema($fuzzphony->registry()->get('products'))->apply($this->connection);

        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_products__vocab')"));
    }

    public function testTheDoctorChecksTheVocabulary(): void
    {
        $fuzzphony = $this->fuzzphony();
        $check = static function (Fuzzphony $f): \Fuzzphony\Core\Inspection\Check {
            foreach ($f->inspect('products')->checks as $c) {
                if ($c->name === 'Vocabulary') {
                    return $c;
                }
            }
            self::fail('No Vocabulary check.');
        };

        $empty = $check($fuzzphony);
        self::assertSame(\Fuzzphony\Core\Inspection\CheckStatus::Warning, $empty->status);
        self::assertStringContainsString('empty', $empty->message);
        self::assertSame('bin/console fuzzphony:reindex products --vocabulary', $empty->fix);

        $fuzzphony->reindex('products');
        $ok = $check($fuzzphony);
        self::assertSame(\Fuzzphony\Core\Inspection\CheckStatus::Ok, $ok->status);
        self::assertStringContainsString('words', $ok->message);

        $this->connection->execute('DROP TABLE "fuzzphony_products__vocab"');
        $missing = $check($fuzzphony);
        self::assertSame(\Fuzzphony\Core\Inspection\CheckStatus::Error, $missing->status);
        self::assertStringContainsString('does not exist', $missing->message);
    }

    public function testRebuildingInsideACallersTransactionJoinsItAndWorksTwice(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->reindex('products');
        $expected = count($this->words());

        $this->connection->transactional(function () use ($fuzzphony, $expected): void {
            $this->connection->execute("INSERT INTO fz_product VALUES (6, 'Zebra lamp', 'Striped', 1, 1000, true, 0, now())");
            $fuzzphony->refresh('products', [6]);
            self::assertSame($expected + 2, $fuzzphony->rebuildVocabulary('products'), 'zebra and lamp');
            self::assertSame($expected + 2, $fuzzphony->rebuildVocabulary('products'), 'a second rebuild in the same transaction');
        });

        self::assertSame($expected + 2, count($this->words()));
    }

    public function testARoleThatMayNotWriteTheVocabularyGetsAnErrorThatNamesWhatItNeeds(): void
    {
        $fuzzphony = $this->fuzzphony();
        $role = 'fz_vocab_' . getmypid();
        $this->connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $this->connection->execute(sprintf('CREATE ROLE %s', $role));
        $this->expectException(\Fuzzphony\Core\Exception\EngineFailure::class);
        $this->expectExceptionMessage('SELECT, INSERT and DELETE');
        try {
            $this->connection->execute(sprintf('GRANT SELECT ON fuzzphony_products, "fuzzphony_products__vocab" TO %s', $role));
            $this->connection->execute(sprintf('SET ROLE %s', $role));
            $fuzzphony->rebuildVocabulary('products');
        } finally {
            $this->connection->execute('RESET ROLE');
            $this->connection->execute(sprintf('DROP OWNED BY %s', $role));
            $this->connection->execute(sprintf('DROP ROLE %s', $role));
        }
    }
}
