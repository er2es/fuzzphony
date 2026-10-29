<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exact-title and prefix bonuses compare the TYPED words with the title: a search-as-you-type text
 * (the last word also a prefix) gets the very bonuses the same text gets without asYouType().
 */
final class AsYouTypeBonusTest extends TestCase
{
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Acme']],
            'products' => [
                [1, 'TV Wall Bracket 1003', 'Holds a screen', 1, 100, true, 1.0, 'now'],
                [2, 'TV Wall Bracket 1003 Pro', 'Holds a big screen', 1, 100, true, 1.0, 'now'],
                [3, 'Premium TV Wall Bracket 1003', 'Holds a screen', 1, 100, true, 1.0, 'now'],
                [4, 'Wireless mouse', 'Silent mouse', 1, 100, true, 1.0, 'now'],
                [5, 'Wire cutter', 'Cuts wire', 1, 100, true, 1.0, 'now'],
                [6, 'Crème brûlée torch', 'Kitchen torch', 1, 100, true, 1.0, 'now'],
                [7, 'Television', 'A screen', 1, 100, true, 1.0, 'now'],
                [8, 'TV', 'A screen', 1, 100, true, 1.0, 'now'],
                [9, 'Wireless mouse pad', 'A pad', 1, 100, true, 1.0, 'now'],
            ],
        ]);
        $index = Indexes::products('manual')
            ->withThresholds(new Thresholds(didYouMean: false, fuzzyMode: FuzzyMode::Never))
            ->withSynonyms(Synonyms::fromEntries([['tv', 'television']]));
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]));
        $this->fuzzphony->schema()->apply($connection);
        $this->fuzzphony->reindex('products');
    }

    private function search(string $text): SearchBuilder
    {
        return $this->fuzzphony->in('products')->query($text);
    }

    /** @return array<string, array{0: string}> */
    public static function typedTexts(): array
    {
        return [
            'title equal to the query' => ['tv wall bracket 1003'],
            'title starts with it' => ['tv wall bracket'],
            'in the middle' => ['wall bracket 1003'],
            'a synonym' => ['tv'],
            'an exclusion' => ['tv wall bracket 1003 -pro'],
            'phrase quotes' => ['"wall bracket" 1003'],
            'a field-scoped word' => ['name:bracket 1003'],
            'accented, mixed case' => ['CRÈME Brûlée torch'],
            'one word' => ['television'],
            'finished last word' => ['wireless mouse'],
        ];
    }

    #[DataProvider('typedTexts')]
    public function testTheBonusesAreTheSameWithAndWithoutAsYouType(string $text): void
    {
        $plain = $this->search($text)->get();
        $typeahead = $this->search($text)->asYouType()->get();

        self::assertNotSame([], $plain->hits, $text);
        $byId = [];
        foreach ($plain->hits as $hit) {
            $byId[$hit->id] = $hit->breakdown;
        }
        $compared = 0;
        foreach ($typeahead->hits as $hit) {
            if (isset($byId[$hit->id])) {
                self::assertSame($byId[$hit->id]->exactBonus, $hit->breakdown->exactBonus, $text . ': exact bonus of ' . $hit->id);
                self::assertSame($byId[$hit->id]->prefixBonus, $hit->breakdown->prefixBonus, $text . ': prefix bonus of ' . $hit->id);
                ++$compared;
            }
        }
        self::assertGreaterThan(0, $compared, $text);
    }

    public function testATitleEqualToWhatWasTypedRanksFirstAndTheTitleItStartsRanksNext(): void
    {
        $hits = $this->search('tv wall bracket 1003')->asYouType()->get()->hits;

        self::assertSame(1, $hits[0]->id);
        self::assertGreaterThan(0.0, $hits[0]->breakdown->exactBonus);
        self::assertSame(0.0, $hits[0]->breakdown->prefixBonus, 'the exact bonus replaces the prefix bonus');
        self::assertSame(2, $hits[1]->id, 'starts with what was typed');
        self::assertSame(0.0, $hits[1]->breakdown->exactBonus);
        self::assertGreaterThan(0.0, $hits[1]->breakdown->prefixBonus);
        self::assertSame(3, $hits[2]->id, 'contains it in the middle: no bonus');
        self::assertSame(0.0, $hits[2]->breakdown->prefixBonus);
    }

    public function testAWordBeingTypedGetsTheBonusesOfTheSameWordTypedAsAPrefix(): void
    {
        $typed = $this->search('wire')->asYouType()->get();
        $byId = [];
        foreach ($typed->hits as $hit) {
            $byId[$hit->id] = $hit->breakdown;
        }
        self::assertEqualsCanonicalizing([4, 5, 9], array_keys($byId));
        foreach ($this->search('wire*')->get()->hits as $hit) {
            self::assertSame($hit->breakdown->exactBonus, $byId[$hit->id]->exactBonus);
            self::assertSame($hit->breakdown->prefixBonus, $byId[$hit->id]->prefixBonus);
        }
        self::assertGreaterThan(0.0, $byId[5]->prefixBonus, '"Wire cutter" starts with the typed "wire"');
    }

    public function testTheBonusParameterIsWhatWasTypedNotTheExpansion(): void
    {
        $typed = $this->search('tv wall bracket 1003')->asYouType()->explain()->statements[0]['params'];
        $whole = $this->search('tv wall bracket 1003')->explain()->statements[0]['params'];

        self::assertContains('tv wall bracket 1003', array_values($whole));
        self::assertContains('tv wall bracket 1003', array_values($typed));
        self::assertNotContains('tv wall bracket 1003 1003', array_values($typed));
    }

    public function testThePositiveWordsOfAnExpandedTextAreTheTypedWords(): void
    {
        $parse = static fn(string $text) => (new QueryParser())->parse($text)->root;

        self::assertSame(['wireless', 'hea'], NodeInspector::positiveWords($parse('wireless (hea | hea*)'), null, true));
        self::assertSame(['wireless', 'hea', 'hea'], NodeInspector::positiveWords($parse('wireless (hea | hea*)')), 'a text typed with the operator, no asYouType');
        self::assertSame(['son'], NodeInspector::positiveWords($parse('brand:son*'), null, true));
        self::assertSame(['a', 'b'], NodeInspector::positiveWords($parse('(a | b*)'), null, true), 'not the same word: not a typeahead pair');
    }
}
