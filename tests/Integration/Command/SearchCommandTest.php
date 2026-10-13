<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->context->applySchemaAndReindex();
        $this->tester = new CommandTester(new SearchCommand($this->context->fuzzphony));
    }

    public function testFindsAKnownHitAndPrintsItsBreakdown(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay(true);
        self::assertStringContainsString('hit(s)', $display);
        self::assertMatchesRegularExpression('/\bid\b.*\bscore\b/s', $display);
        self::assertStringContainsString(' 1 ', $display, 'the wireless mouse (id 1) should be listed');
    }

    public function testWarningsNamingTheUsersWordsAreNotReadAsConsoleStyleTags(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'wireless mouse <comment>zzqqx</comment>'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('ignored words that match nothing: "<comment>zzqqx</comment>"', $this->tester->getDisplay(true));
    }

    public function testWhereFilterNarrowsResults(): void
    {
        $status = $this->tester->execute([
            'index' => 'products',
            'query' => 'mouse',
            '--where' => ['in_stock=true', 'price<=5000'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Gaming mouse RGB', $this->tester->getDisplay(true));
    }

    public function testInvalidWhereFilterIsRejected(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--where' => ['not a filter']], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Cannot parse filter', $this->tester->getDisplay(true));
    }

    public function testExplainPrintsSqlAndPlan(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay(true);
        self::assertStringContainsString('SQL:', $display);
        self::assertStringContainsString('Plan', $display);
    }

    public function testBrowsingWithoutAQueryStillReturnsResults(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--where' => ['in_stock=false']], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('(no text: browsing)', $this->tester->getDisplay(true));
    }

    public function testThresholdOverrideNarrowsWhatCountsAsAHit(): void
    {
        $status = $this->tester->execute([
            'index' => 'products',
            'query' => 'mouse',
            '--threshold' => ['min_score=99'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('0 hit(s)', $this->tester->getDisplay(true), 'an unreachable min_score must leave no hit standing');
    }

    public function testFuzzySimilarityCanBeSetFlatOrBackToByWordLength(): void
    {
        // the typo-tolerant statement measures the word's length in SQL only when the similarity is by word length
        $sql = function (string $threshold): string {
            $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true, '--threshold' => ['fuzzy_mode=always', $threshold]], ['interactive' => false]);

            return $this->tester->getDisplay(true);
        };

        self::assertStringNotContainsString('char_length(replace(', $sql('fuzzy_similarity=0.3'), 'a number is flat');
        self::assertStringContainsString('char_length(replace(', $sql('fuzzy_similarity=null'));
        self::assertStringContainsString('char_length(replace(', $sql('fuzzy_similarity=NULL'), 'case does not matter');
    }

    public function testCompletesIndexNames(): void
    {
        $completion = new CommandCompletionTester(new SearchCommand($this->context->fuzzphony));

        self::assertSame(['products'], $completion->complete(['']));
    }

    public function testPrintsTheSpellingItSuggests(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'hedphones'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Did you mean headphones?', $this->tester->getDisplay(true));

        $this->tester->execute(['index' => 'products', 'query' => 'headphones'], ['interactive' => false]);
        self::assertStringNotContainsString('Did you mean', $this->tester->getDisplay(true));
    }

    public function testFacetsAreCountedAndPrinted(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--facet' => ['in_stock', 'brand_id'], '--exact' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('in_stock: true (3)', $this->tester->getDisplay(true));
        self::assertMatchesRegularExpression('/brand_id: 1 \(2\), 2 \(1\)/', $this->tester->getDisplay(true));
    }

    public function testSuggestCompletesTheLastWord(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'wireless hea', '--suggest' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('wireless headphones', $this->tester->getDisplay(true));

        $this->tester->execute(['index' => 'products', 'query' => 'qq', '--suggest' => true], ['interactive' => false]);
        self::assertStringContainsString('No completions.', $this->tester->getDisplay(true));
    }

    public function testTenantAndUserSearchATenantScopedIndex(): void
    {
        $tester = $this->tenantTester();

        // Brand 1 (Logitech) has products 1 and 4; brand 2 (Razer) has product 2. All three mention "mouse".
        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--tenant' => '1', '--user' => 'ada'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('2 hit(s)', $tester->getDisplay(true));
    }

    public function testAUserWithoutATenantIsRefusedOnATenantScopedIndex(): void
    {
        $tester = $this->tenantTester();

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--user' => 'ada'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('products', $tester->getDisplay(true));
        self::assertStringNotContainsString('hit(s)', $tester->getDisplay(true));
    }

    public function testADigitsTenantWorksOnAStringTypedTenantFilter(): void
    {
        // the tenant column is text ("1" is Logitech's brand_id as text): --tenant=1 must stay the string "1"
        $index = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, p.description, p.brand_id::text AS brand_key FROM fz_product p')
            ->watch('fz_product')
            ->field('name', 'A', fuzzy: true)
            ->field('description', 'D')
            ->filter('brand_key', 'string')
            ->tenant('brand_key')
            ->sync('manual')
            ->build();
        $fuzzphony = new Fuzzphony($this->context->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->context->connection);
        $fuzzphony->reindex('products');
        $tester = new CommandTester(new SearchCommand($fuzzphony));

        // Brand 1 (Logitech) has products 1 and 4; brand 2 (Razer) has product 2. All three mention "mouse".
        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--tenant' => '1'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay(true));
        self::assertStringContainsString('2 hit(s)', $tester->getDisplay(true));
    }

    public function testATenantScopedIndexWithoutTenantOrUserIsAMessageNotAnException(): void
    {
        $tester = $this->tenantTester();

        $status = $tester->execute(['index' => 'products', 'query' => 'mouse'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('requires forTenant()', $tester->getDisplay(true));
    }

    public function testTheOperatorSearchRunsNoGuardWhileTheApplicationGateDenies(): void
    {
        // what the bundle wires: the application's Fuzzphony has a gate with a denying guard, the command's one has none
        $denying = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                throw AccessDenied::forIndex($index->name);
            }
        };
        $registry = new IndexRegistry([Indexes::products(tenant: true)]);
        $application = new Fuzzphony($this->context->engine, $registry, new SearchGate(guards: [$denying]));
        $operator = new Fuzzphony($this->context->engine, $registry, new SearchGate());
        $application->schema()->apply($this->context->connection);
        $application->reindex('products');

        try {
            $application->in('products')->query('mouse')->asActor(new Actor(tenant: 1, user: 'ada'))->get();
            self::fail('the application gate denies');
        } catch (AccessDenied) {
        }

        $tester = new CommandTester(new SearchCommand($operator));
        $status = $tester->execute(['index' => 'products', 'query' => 'mouse', '--tenant' => '1', '--user' => 'ada'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay(true));
        self::assertStringContainsString('2 hit(s)', $tester->getDisplay(true));
    }

    private function tenantTester(): CommandTester
    {
        $connection = $this->context->connection;
        $fuzzphony = new Fuzzphony($this->context->engine, new IndexRegistry([Indexes::products(tenant: true)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return new CommandTester(new SearchCommand($fuzzphony));
    }

    public function testAsYouTypeMatchesTheBeginningOfTheLastWord(): void
    {
        $this->tester->execute(['index' => 'products', 'query' => 'mo'], ['interactive' => false]);
        self::assertStringContainsString('0 hit(s)', $this->tester->getDisplay(true));

        $this->tester->execute(['index' => 'products', 'query' => 'mo', '--as-you-type' => true], ['interactive' => false]);
        self::assertStringContainsString('3 hit(s)', $this->tester->getDisplay(true));
    }
}
