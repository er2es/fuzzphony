<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** What fuzzphony:doctor prints and exits with for each kind of check, against an engine that reports canned checks. */
final class DoctorCommandReportTest extends TestCase
{
    public function testTheTextReportListsEveryCheckWithItsSymbolAndTheFixesOfTheProblems(): void
    {
        $tester = $this->tester([
            Check::ok('Table', 'fuzzphony_products exists'),
            Check::warning('Coverage', '3 documents missing', 'bin/console fuzzphony:reindex products'),
            Check::error('Trigger', 'trigger missing', 'CREATE TRIGGER x'),
            Check::skipped('Queue', 'manual sync'),
            Check::warning('Statistics', 'stale'),
        ]);

        $status = $tester->execute([], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("Index \"products\"\n", $display);
        self::assertMatchesRegularExpression('/^ {6}Check {8}Result +$/m', $display, 'the first, symbol column has no title');
        self::assertMatchesRegularExpression('/^\s+✔\s+Table\s+fuzzphony_products exists\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s+!\s+Coverage\s+3 documents missing\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s+✘\s+Trigger\s+trigger missing\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s+-\s+Queue\s+manual sync\s*$/m', $display);
        self::assertStringContainsString(' Fix for "Coverage": bin/console fuzzphony:reindex products', $display);
        self::assertStringContainsString(' Fix for "Trigger": CREATE TRIGGER x', $display);
        self::assertSame(2, substr_count($display, 'Fix for'), 'a problem without a fix, and the checks that are fine, have none');
        self::assertStringContainsString('Problems found; see the fixes above.', $display);
    }

    public function testTheSectionShowsWhereTheDefinitionCameFrom(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('inspect')->willReturnCallback(static fn(IndexDefinition $index): InspectionReport => new InspectionReport($index->name, [Check::ok('Table', 'fine')]));
        $registry = new IndexRegistry([Indexes::products('manual')]);
        $registry->annotateOrigin('products', 'definition class App\ProductAdminIndex');
        $tester = new CommandTester(new DoctorCommand(new Fuzzphony($engine, $registry)));

        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('Index "products" (definition class App\ProductAdminIndex)', $tester->getDisplay(true));
    }

    /** @return iterable<string, array{list<Check>, bool, string, int}> */
    public static function outcomes(): iterable
    {
        $ok = [Check::ok('A', 'fine'), Check::skipped('B', 'n/a')];
        $warn = [Check::ok('A', 'fine'), Check::warning('B', 'hmm')];
        $error = [Check::warning('A', 'hmm'), Check::error('B', 'broken')];

        yield 'healthy' => [$ok, false, 'All checks passed.', Command::SUCCESS];
        yield 'healthy, strict' => [$ok, true, 'All checks passed.', Command::SUCCESS];
        yield 'warnings' => [$warn, false, 'Healthy, with warnings.', Command::SUCCESS];
        yield 'warnings, strict' => [$warn, true, 'Healthy, with warnings.', Command::FAILURE];
        yield 'errors' => [$error, false, 'Problems found; see the fixes above.', Command::FAILURE];
        yield 'errors, strict' => [$error, true, 'Problems found; see the fixes above.', Command::FAILURE];
    }

    /** @param list<Check> $checks */
    #[DataProvider('outcomes')]
    public function testTheTextReportEndsWithTheWorstStatusAndTheExitCode(array $checks, bool $strict, string $message, int $exit): void
    {
        $tester = $this->tester($checks);

        $status = $tester->execute(['--strict' => $strict], ['interactive' => false]);

        self::assertSame($exit, $status, $tester->getDisplay(true));
        self::assertStringContainsString($message, $tester->getDisplay(true));
    }

    /** @param list<Check> $checks */
    #[DataProvider('outcomes')]
    public function testFailOnWarningIsTheLongFormOfStrict(array $checks, bool $strict, string $message, int $exit): void
    {
        $tester = $this->tester($checks);

        $status = $tester->execute($strict ? ['--fail-on' => 'warning'] : ['--fail-on' => 'error'], ['interactive' => false]);

        self::assertSame($exit, $status, $tester->getDisplay(true));
    }

    public function testFailOnWarningAlsoGovernsThePrometheusSnapshot(): void
    {
        self::assertSame(Command::FAILURE, $this->tester([Check::warning('A', 'x')])->execute(['--format' => 'prometheus', '--fail-on' => 'warning']));
        self::assertSame(Command::SUCCESS, $this->tester([Check::warning('A', 'x')])->execute(['--format' => 'prometheus']));
    }

    public function testFailOnTakesErrorOrWarningOnly(): void
    {
        $tester = $this->tester([Check::ok('A', 'x')]);

        self::assertSame(Command::INVALID, $tester->execute(['--fail-on' => 'notice']));
        self::assertStringContainsString('--fail-on must be "error" or "warning"', $tester->getDisplay(true));
    }

    /** @param list<Check> $checks */
    #[DataProvider('outcomes')]
    public function testThePrometheusSnapshotExitsLikeTheTextReport(array $checks, bool $strict, string $message, int $exit): void
    {
        $tester = $this->tester($checks);

        $status = $tester->execute(['--format' => 'prometheus', '--strict' => $strict], ['interactive' => false]);

        self::assertSame($exit, $status, $tester->getDisplay(true));
        self::assertStringNotContainsString($message, $tester->getDisplay(true), 'no prose in the snapshot');
    }

    public function testThePrometheusSnapshotIsOneIfTheCheckIsOkAndZeroOtherwise(): void
    {
        $tester = $this->tester([Check::ok('Table', 'x'), Check::warning('Coverage', 'x'), Check::error('Trigger', 'x'), Check::skipped('Queue', 'x')]);

        $tester->execute(['--format' => 'prometheus'], ['interactive' => false]);

        self::assertSame(
            [
                'fuzzphony_doctor_check{index="products",check="Table"} 1',
                'fuzzphony_doctor_check{index="products",check="Coverage"} 0',
                'fuzzphony_doctor_check{index="products",check="Trigger"} 0',
                'fuzzphony_doctor_check{index="products",check="Queue"} 0',
            ],
            array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay(true))), static fn(string $line): bool => $line !== '')),
        );
    }

    /** @param list<Check> $checks */
    private function tester(array $checks): CommandTester
    {
        $engine = self::createStub(Engine::class);
        $engine->method('inspect')->willReturnCallback(static fn(IndexDefinition $index): InspectionReport => new InspectionReport($index->name, $checks));

        return new CommandTester(new DoctorCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]))));
    }
}
