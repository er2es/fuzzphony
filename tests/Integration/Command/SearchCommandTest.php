<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SearchCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
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
        $this->tester->setInteractive(false);
    }

    public function testFindsAKnownHitAndPrintsItsBreakdown(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse']);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('hit(s)', $display);
        self::assertMatchesRegularExpression('/\bid\b.*\bscore\b/s', $display);
        self::assertStringContainsString(' 1 ', $display, 'the wireless mouse (id 1) should be listed');
    }

    public function testWhereFilterNarrowsResults(): void
    {
        $status = $this->tester->execute([
            'index' => 'products',
            'query' => 'mouse',
            '--where' => ['in_stock=true', 'price<=5000'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Gaming mouse RGB', $this->tester->getDisplay());
    }

    public function testInvalidWhereFilterIsRejected(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--where' => ['not a filter']]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Cannot parse filter', $this->tester->getDisplay());
    }

    public function testExplainPrintsSqlAndPlan(): void
    {
        $status = $this->tester->execute(['index' => 'products', 'query' => 'mouse', '--explain' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('SQL:', $display);
        self::assertStringContainsString('Plan', $display);
    }

    public function testBrowsingWithoutAQueryStillReturnsResults(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--where' => ['in_stock=false']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('(no text: browsing)', $this->tester->getDisplay());
    }
}
