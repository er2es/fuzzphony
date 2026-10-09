<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SearchCommand;
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
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('hit(s)', $display);
        self::assertMatchesRegularExpression('/\bid\b.*\bscore\b/s', $display);
        self::assertStringContainsString(' 1 ', $display, 'the wireless mouse (id 1) should be listed');
    }

    public function testWarningsNamingTheUsersWordsAreNotReadAsConsoleStyleTags(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'wireless mouse <comment>zzqqx</comment>'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('ignored words that match nothing: "<comment>zzqqx</comment>"', $this->tester->getDisplay());
    }

    public function testWhereFilterNarrowsResults(): void
    {
        $status = $this->tester->execute([
            'index' => 'products',
            'query' => 'mouse',
            '--where' => ['in_stock=true', 'price<=5000'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Gaming mouse RGB', $this->tester->getDisplay());
    }

    public function testInvalidWhereFilterIsRejected(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--where' => ['not a filter']], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Cannot parse filter', $this->tester->getDisplay());
    }

    public function testExplainPrintsSqlAndPlan(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('SQL:', $display);
        self::assertStringContainsString('Plan', $display);
    }

    public function testBrowsingWithoutAQueryStillReturnsResults(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--where' => ['in_stock=false']], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('(no text: browsing)', $this->tester->getDisplay());
    }

    public function testThresholdOverrideNarrowsWhatCountsAsAHit(): void
    {
        $status = $this->tester->execute([
            'index' => 'products',
            'query' => 'mouse',
            '--threshold' => ['min_score=99'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('0 hit(s)', $this->tester->getDisplay(), 'an unreachable min_score must leave no hit standing');
    }

    public function testFuzzySimilarityCanBeSetFlatOrBackToByWordLength(): void
    {
        // the typo-tolerant statement measures the word's length in SQL only when the similarity is by word length
        $sql = function (string $threshold): string {
            $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true, '--threshold' => ['fuzzy_mode=always', $threshold]], ['interactive' => false]);

            return $this->tester->getDisplay();
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
        self::assertStringContainsString('Did you mean headphones?', $this->tester->getDisplay());

        $this->tester->execute(['index' => 'products', 'query' => 'headphones'], ['interactive' => false]);
        self::assertStringNotContainsString('Did you mean', $this->tester->getDisplay());
    }
}
