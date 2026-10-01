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
    }

    public function testDefaultRunPrintsSqlWithoutApplying(): void
    {
        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('CREATE', $this->tester->getDisplay());
        self::assertStringContainsString('Nothing was executed', $this->tester->getDisplay());
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'nothing should have been applied');
    }

    public function testApplyActuallyCreatesTheSchema(): void
    {
        $status = $this->tester->execute(['--apply' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('statement(s) applied', $this->tester->getDisplay());
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'the sidecar table must now exist');
    }

    public function testDropRemovesTheSchemaAfterApplying(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));

        $status = $this->tester->execute(['--drop' => true, '--apply' => true, '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'the sidecar table must be gone');
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fz_product')"), 'the source table must never be touched');
    }

    public function testDropWithForceSkipsTheConfirmationEntirely(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);

        $status = $this->tester->execute(['--drop' => true, '--apply' => true, '--force' => true], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Drop these Fuzzphony objects?', $this->tester->getDisplay());
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));
    }

    public function testDropRefusesNonInteractivelyWithoutForce(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"));

        $status = $this->tester->execute(['--drop' => true, '--apply' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Refusing to drop without confirmation: pass --force in non-interactive runs.', $this->tester->getDisplay());
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'nothing should have been dropped');
    }

    public function testDropAsksAndProceedsOnYes(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        $this->tester->setInputs(['yes']);

        $status = $this->tester->execute(['--drop' => true, '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Drop these Fuzzphony objects? (yes/no) [no]:', $this->tester->getDisplay());
        self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'the sidecar table must be gone');
    }

    public function testDropAsksAndRefusesOnNo(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        $this->tester->setInputs(['no']);

        $status = $this->tester->execute(['--drop' => true, '--apply' => true]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Drop these Fuzzphony objects? (yes/no) [no]:', $this->tester->getDisplay());
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'nothing should have been dropped');
    }

    public function testDropAsksAndDefaultsToNoOnEmptyAnswer(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        $this->tester->setInputs(['']);

        $status = $this->tester->execute(['--drop' => true, '--apply' => true]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'nothing should have been dropped');
    }

    public function testDropExplainsWhatWillBeRemovedBeforeAsking(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);

        $this->tester->execute(['--drop' => true, '--apply' => true], ['interactive' => false]);

        self::assertStringContainsString(
            'This will drop the Fuzzphony objects for products: the sidecar table, its triggers, functions and queued rows; your source tables are not touched.',
            $this->tester->getDisplay(),
        );
    }

    public function testDumpMigrationWritesAMigrationFile(): void
    {
        $directory = sys_get_temp_dir() . '/fuzzphony-schema-command-test-' . bin2hex(random_bytes(4));
        try {
            $status = $this->tester->execute(['--dump-migration' => $directory], ['interactive' => false]);

            self::assertSame(Command::SUCCESS, $status);
            $files = glob($directory . '/Version*.php');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $contents = file_get_contents($files[0]);
            self::assertIsString($contents);
            self::assertStringContainsString('Fuzzphony search indexes', $contents);
            self::assertStringContainsString('isTransactional', $contents);
            // every plan records itself: the shared objects' row "*" (end of the global plan), then one row per index
            $upsert = '$this->addSql(\'INSERT INTO "public"."fuzzphony_meta" (index_name, layout_version,';
            self::assertSame(2, substr_count($contents, $upsert));
            self::assertStringContainsString("VALUES (\\'*\\', 2, ", $contents);
            // the last statement of up() records the index's layout and definition (var_export escapes the quotes)
            $last = substr($contents, (int) strrpos($contents, '$this->addSql('));
            self::assertStringStartsWith($upsert, $last);
            self::assertStringContainsString("VALUES (\\'products\\', 2, ", $last);
            self::assertStringContainsString('ON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version', $last);
            self::assertNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_products')"), 'dumping a migration must not apply anything');
        } finally {
            $leftover = glob($directory . '/*.php');
            array_map('unlink', $leftover !== false ? $leftover : []);
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDumpMigrationFailsCleanlyWhenTheDirectoryCannotBeCreated(): void
    {
        // A plain file already occupies that path, so mkdir() cannot turn it into a directory.
        // mkdir() raises a PHP warning on failure, which a temporary error handler swallows.
        $path = sys_get_temp_dir() . '/fuzzphony-schema-command-test-blocked-' . bin2hex(random_bytes(4));
        file_put_contents($path, 'not a directory');
        set_error_handler(static fn(int $errno, string $errstr): bool => str_contains($errstr, 'mkdir()'));
        try {
            $status = $this->tester->execute(['--dump-migration' => $path], ['interactive' => false]);
        } finally {
            restore_error_handler();
            unlink($path);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Cannot create directory', $this->tester->getDisplay());
    }

    public function testCompletesIndexNames(): void
    {
        $completion = new CommandCompletionTester(new SchemaCommand($this->context->fuzzphony, $this->context->connection));

        self::assertSame(['products'], $completion->complete(['']));
    }
}
