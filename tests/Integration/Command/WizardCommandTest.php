<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WizardCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $introspector = new PostgresIntrospector($this->context->connection);
        $this->tester = new CommandTester(new WizardCommand($introspector, $this->context->engine, $this->context->connection));
    }

    public function testSuggestsAnIndexForAKnownTable(): void
    {
        $status = $this->tester->execute(['table' => 'fz_product', '--format' => 'yaml'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Suggested index for "fz_product"', $display);
        self::assertStringContainsString('name:', $display);
        self::assertStringContainsString('config/packages/fuzzphony.yaml', $display);
    }

    public function testBuilderFormatIsAlsoExportable(): void
    {
        $status = $this->tester->execute(['table' => 'fz_product', '--format' => 'builder'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('IndexDefinition::builder', $this->tester->getDisplay());
    }

    public function testWriteOptionSavesToAFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fuzzphony-wizard-');
        self::assertIsString($file);
        try {
            $status = $this->tester->execute(['table' => 'fz_product', '--write' => $file], ['interactive' => false]);

            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('Written to', $this->tester->getDisplay());
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            self::assertStringContainsString('name:', $contents);
        } finally {
            unlink($file);
        }
    }

    public function testTryOptionCreatesReindexesAndInspectsTheSuggestedIndex(): void
    {
        $status = $this->tester->execute(['table' => 'fz_product', '--try' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Trying it', $display);
        self::assertStringContainsString('documents, doctor:', $display);
        self::assertNotNull(
            $this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_fz_products')"),
            'the --try run must have actually applied the schema for the suggested index',
        );
    }

    public function testTryOptionWithInteractiveSamplesRunsSearchesUntilABlankAnswer(): void
    {
        // "" accepts every suggested field (the interactive keep-fields prompt runs first), then
        // "mouse" runs one sample search and "" ends the loop.
        $this->tester->setInputs(['', 'mouse', '']);

        $status = $this->tester->execute(['table' => 'fz_product', '--try' => true], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Try a search (empty to finish)', $display);
        self::assertMatchesRegularExpression('/\d+ hit\(s\), [\d.]+ ms.*: ids /', $display);
    }

    public function testUnknownTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tester->execute(['table' => 'no_such_table'], ['interactive' => false]);
    }
}
