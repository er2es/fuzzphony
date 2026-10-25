<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\SchemaCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:schema: the progress it shows while applying, and the migration file it writes (name, class, location, permissions). */
final class SchemaCommandOutputTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;
    private string $directory;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->tester = new CommandTester(new SchemaCommand($this->context->fuzzphony, $this->context->connection));
        $this->directory = sys_get_temp_dir() . '/fuzzphony-schema-output-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testApplyShowsAProgressBarThatEndsAtTheNumberOfStatements(): void
    {
        $status = $this->tester->execute(['--apply' => true], ['interactive' => false]);

        $display = $this->tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame(1, preg_match('/(\d+) statement\(s\) applied/', $display, $applied));
        $count = (int) ($applied[1] ?? 0);
        self::assertGreaterThan(1, $count);
        self::assertMatchesRegularExpression(sprintf('/ %1$d\/%1$d \[[^\]]+\] 100%%/', $count), $display, 'the bar ends complete');
    }

    public function testAddingAColumnToATableThatWasThereAsksForARestartOfTheLongRunningProcesses(): void
    {
        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertStringNotContainsString('restart', $this->tester->getDisplay(true), 'a fresh install has no process that knows the tables');

        $this->tester->execute(['--apply' => true], ['interactive' => false]);
        self::assertStringNotContainsString('restart', $this->tester->getDisplay(true), 'nothing was added');

        $this->context->connection->execute('ALTER TABLE fuzzphony_products DROP COLUMN tsv_capped');
        $status = $this->tester->execute(['--apply' => true], ['interactive' => false]);
        $display = preg_replace('/\s+/', ' ', $this->tester->getDisplay(true)) ?? '';
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('restart long-running processes (Messenger workers, RoadRunner, FrankenPHP)', $display);
        self::assertStringContainsString('resetCaches()', $display);
    }

    public function testDumpMigrationNamesTheFileAndTheClassAfterTheTimeAndSaysWhereItWentWithoutPrintingTheSql(): void
    {
        $status = $this->tester->execute(['--dump-migration' => $this->directory, '--namespace' => 'App\\Migrations'], ['interactive' => false]);

        $display = $this->tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        $files = glob($this->directory . '/*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('~/Version\d{14}\.php$~', $files[0]);
        $class = basename($files[0], '.php');
        $contents = file_get_contents($files[0]);
        self::assertIsString($contents);
        self::assertStringContainsString('namespace App\\Migrations;', $contents);
        self::assertStringContainsString(sprintf('final class %s extends AbstractMigration', $class), $contents);
        self::assertStringContainsString(
            sprintf('Migration written to %s (non-transactional, because indexes are built concurrently).', $files[0]),
            preg_replace('/\s+/', ' ', $display) ?? '',
        );
        self::assertStringNotContainsString('Nothing was executed', $display, 'the SQL is neither printed nor hinted at');
        self::assertStringNotContainsString('CREATE', $display);
    }

    public function testATrailingSlashOfTheDirectoryIsNotDoubled(): void
    {
        $this->tester->execute(['--dump-migration' => $this->directory . '/'], ['interactive' => false]);

        $files = glob($this->directory . '/Version*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertStringNotContainsString($this->directory . '//', preg_replace('/\s+/', '', $this->tester->getDisplay(true)) ?? '');
    }

    public function testTheMigrationDirectoryIsCreatedWithGroupWritablePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Directory permissions are not modelled on Windows.');
        }

        $this->tester->execute(['--dump-migration' => $this->directory . '/nested/deeper'], ['interactive' => false]);

        $expected = 0o775 & ~umask();
        self::assertSame($expected, fileperms($this->directory . '/nested/deeper') & 0o777);
        self::assertSame($expected, fileperms($this->directory . '/nested') & 0o777);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
