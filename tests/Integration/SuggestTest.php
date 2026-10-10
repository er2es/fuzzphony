<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Search-as-you-type: the word being typed is completed from the vocabulary. */
final class SuggestTest extends TestCase
{
    private function fuzzphony(bool $tenant = false): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testTheLastWordIsCompletedWithTheMostFrequentWordFirst(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame(['mouse'], $fuzzphony->suggest('products', 'mo'));
        self::assertSame(['wireless'], $fuzzphony->suggest('products', 'wire'));
        self::assertSame(['wireless headphones'], $fuzzphony->suggest('products', 'wireless hea'), 'the words before it stay as typed');
        self::assertSame(['mouse'], $fuzzphony->suggest('products', 'MOU'), 'case does not matter');
        self::assertSame(['-mouse'], $fuzzphony->suggest('products', '-mo'), 'an operator before the word stays');
        self::assertSame(['razer', 'rgb'], $fuzzphony->suggest('products', 'r'), 'equally frequent words in alphabetical order');
        self::assertSame(['razer'], $fuzzphony->suggest('products', 'r', 1));
    }

    public function testAccentsAreFoldedAndNothingIsSuggestedWhenThereIsNoWordBeingTyped(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame(['creme'], $fuzzphony->suggest('products', 'Crè'), 'the vocabulary has the folded word');
        self::assertSame([], $fuzzphony->suggest('products', 'mouse '), 'the word is finished');
        self::assertSame([], $fuzzphony->suggest('products', 'mouse*'), 'a symbol ends the text');
        self::assertSame([], $fuzzphony->suggest('products', ''));
        self::assertSame([], $fuzzphony->suggest('products', 'qq'));
    }

    public function testThePrefixIsNeverAPattern(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame([], $fuzzphony->suggest('products', '%'));
        self::assertSame([], $fuzzphony->suggest('products', 'm_'), 'an underscore is not a wildcard');
        self::assertSame([], $fuzzphony->suggest('products', "mouse'; DROP TABLE x; --"));
    }

    public function testATenantScopedIndexSuggestsNothing(): void
    {
        self::assertSame([], $this->fuzzphony(tenant: true)->suggest('products', 'mo'), 'the vocabulary has no tenant column');
    }

    public function testAnIndexWithoutAVocabularyYetSuggestsNothing(): void
    {
        $fuzzphony = $this->fuzzphony();
        PostgresTestCase::connect()->execute('TRUNCATE "fuzzphony_products__vocab"');

        self::assertSame([], $fuzzphony->suggest('products', 'mo'));
    }

    public function testTheLimitIsChecked(): void
    {
        $fuzzphony = $this->fuzzphony();

        foreach ([0, 21] as $limit) {
            try {
                $fuzzphony->suggest('products', 'mo', $limit);
                self::fail('Expected InvalidArgument.');
            } catch (InvalidArgument $e) {
                self::assertStringContainsString('between 1 and 20', $e->getMessage());
            }
        }
    }

    public function testAnEngineWithoutSuggestionsGivesNone(): void
    {
        $fuzzphony = new Fuzzphony(self::createStub(\Fuzzphony\Core\Engine\Engine::class), new IndexRegistry([Indexes::products('manual')]));

        self::assertSame([], $fuzzphony->suggest('products', 'mo'));
    }
}
