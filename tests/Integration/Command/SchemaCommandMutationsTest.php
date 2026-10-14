<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SchemaCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:schema --apply: the progress bar is closed (with a blank line) before the result is printed. */
final class SchemaCommandMutationsTest extends TestCase
{
    public function testTheProgressBarIsFinishedWithABlankLineBeforeTheResult(): void
    {
        $context = new CommandTestCase();
        $tester = new CommandTester(new SchemaCommand($context->fuzzphony, $context->connection));

        $status = $tester->execute(['--apply' => true], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertMatchesRegularExpression('/ (\d+)\/\1 \[[^\]]+\] 100%\n\n \[OK\] \1 statement\(s\) applied/', $display);
    }
}
