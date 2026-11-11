<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** Edges of SearchRunner: how long an answer says it took, what a rescue tells, when the explanation looks for typos. */
final class SearchRunnerGapsTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, [
            'brands' => [[1, 'Logitech']],
            'products' => [
                [1, 'Wireless mouse', 'Silent wireless mouse', 1, 3_990, true, 5.0, 'now'],
                [2, 'Mouse pad', 'Large mouse pad', 1, 2_990, false, 1.0, 'now'],
            ],
        ]);
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'D')
            ->language('english')
            ->sync('manual')
            ->profile('nofuzz', new RankingProfile(fuzzy: 0.0))
            ->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]), new SearchGate());
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    public function testTheTimeOfARescuedAnswerIsInMillisecondsWithThreeDecimals(): void
    {
        $f = $this->fuzzphony();
        $times = [];
        for ($i = 0; $i < 40; ++$i) {
            $result = $f->in('products')->query('mpuse')->get();
            self::assertNotNull($result->correctedTo);
            $times[] = $result->tookMs;
        }

        foreach ($times as $ms) {
            self::assertGreaterThan(0.0, $ms);
            self::assertLessThan(60_000.0, $ms);
            self::assertEqualsWithDelta(round($ms, 3), $ms, 1e-9, 'at most three decimals');
        }
        self::assertNotSame([], array_filter($times, static fn(float $ms): bool => (int) round($ms * 1000) % 10 !== 0), 'a time is not rounded to two decimals');
        self::assertNotSame([], array_filter($times, static fn(float $ms): bool => $ms !== floor($ms)), 'nor to whole milliseconds');
        self::assertNotSame([], array_filter($times, static fn(float $ms): bool => $ms !== ceil($ms)));
    }

    public function testARescueKeepsTheWarningsOfTheTypedSearchAndAddsItsOwn(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mpuse)')->get();

        self::assertSame('mouse', $result->correctedTo);
        self::assertSame(['Ignored an unmatched ")".', "No results for 'mpuse'; showing results for 'mouse'."], $result->warnings);
    }

    public function testTheExplanationLooksForTyposOnlyWhenTheProfileUsesThem(): void
    {
        $f = $this->fuzzphony();
        $via = static fn(string $profile): array => array_map(
            static fn($m): string => $m->word . ':' . $m->via,
            $f->in('products')->query('mouse wirelss')->profile($profile)->explainMatches()->get()->hits[0]->matches ?? [],
        );

        self::assertContains('wireless:typo', $via('default'));
        self::assertNotContains('wireless:typo', $via('nofuzz'));
        $never = $f->in('products')->query('mouse wirelss')->thresholds(['typo' => ['mode' => 'never']])->explainMatches()->get();
        foreach ($never->hits as $hit) {
            foreach ($hit->matches ?? [] as $m) {
                self::assertNotSame('typo', $m->via);
            }
        }
    }

    public function testAnAlwaysTypoStatementOfARelaxedSearchKeepsItsLabelPrefix(): void
    {
        $explanation = $this->fuzzphony()->in('products')->query('mouse')->thresholds(['typo' => ['mode' => 'always']])->explain();
        self::assertContains('full-text + fuzzy', array_column($explanation->statements, 'label'));

        $relaxed = $this->fuzzphony()->in('products')->query('mouse zzzzqq')->thresholds(['typo' => ['mode' => 'always']])->explain();
        self::assertContains('relaxed: full-text + fuzzy', array_column($relaxed->statements, 'label'));
    }
}
