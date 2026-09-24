<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SchemaCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->tester = new CommandTester(new SchemaCommand($this->context->fuzzphony, $this->context->connection));
        $this->tester->setInteractive(false);
    }

    public function testDefaultRunPrintsSqlWithoutApplying(): void
    {
        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('CREATE', $this->tester->getDisplay());
        self::assertStringContainsString('Nothing was executed', $this->tester->getDisplay());
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'nothing should have been applied');
    }

    public function testApplyActuallyCreatesTheSchema(): void
    {
        $status = $this->tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('statement(s) applied', $this->tester->getDisplay());
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'the sidecar table must now exist');
    }

    public function testDropRemovesTheSchemaAfterApplying(): void
    {
        $this->tester->execute(['--apply' => true]);
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));

        $status = $this->tester->execute(['--drop' => true, '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'the sidecar table must be gone');
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fz_product')"), 'the source table must never be touched');
    }

    public function testDumpMigrationWritesAMigrationFile(): void
    {
        $directory = sys_get_temp_dir() . '/fuzzphony-schema-command-test-' . bin2hex(random_bytes(4));
        try {
            $status = $this->tester->execute(['--dump-migration' => $directory]);

            self::assertSame(Command::SUCCESS, $status);
            $files = glob($directory . '/Version*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $contents = file_get_contents($files[0]);
            self::assertIsString($contents);
            self::assertStringContainsString('Fuzzphony search indexes', $contents);
            self::assertStringContainsString('isTransactional', $contents);
            self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'dumping a migration must not apply anything');
        } finally {
            $leftover = glob($directory . '/*.php');
            array_map('unlink', $leftover !== false ? $leftover : []);
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testCompletesIndexNames(): void
    {
        $completion = new CommandCompletionTester(new SchemaCommand($this->context->fuzzphony, $this->context->connection));

        self::assertSame(['products'], $completion->complete(['']));
    }
}
