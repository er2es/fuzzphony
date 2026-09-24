<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\DoctorCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * DoctorCommand's own help text documents: "Exit code 0 = healthy, 1 = errors (or warnings with --strict)."
 * These tests exercise all three outcomes against a real Postgres.
 */
final class DoctorCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->tester = new CommandTester(new DoctorCommand($this->context->fuzzphony));
    }

    public function testHealthyIndexExitsZero(): void
    {
        $this->context->applySchemaAndReindex();

        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('All checks passed', $this->tester->getDisplay());
    }

    public function testMissingSchemaIsAnErrorAndExitsOne(): void
    {
        // schema never applied: the sidecar table check must fail with CheckStatus::Error
        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Problems found', $this->tester->getDisplay());
    }

    public function testStaleCoverageIsAWarningThatExitsZeroWithoutStrict(): void
    {
        $this->context->applySchemaAndReindex();
        // insert a new source row without reindexing it: coverage drops below 100%
        $this->context->connection->execute(
            "INSERT INTO fz_product VALUES (99, 'Unreindexed gadget', 'not yet in the sidecar', 1, 1000, true, 0, now())",
        );

        $status = $this->tester->execute(['--deep' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Healthy, with warnings', $this->tester->getDisplay());
    }

    public function testStaleCoverageWithStrictExitsOne(): void
    {
        $this->context->applySchemaAndReindex();
        $this->context->connection->execute(
            "INSERT INTO fz_product VALUES (99, 'Unreindexed gadget', 'not yet in the sidecar', 1, 1000, true, 0, now())",
        );

        $status = $this->tester->execute(['--deep' => true, '--strict' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Healthy, with warnings', $this->tester->getDisplay());
    }
}
