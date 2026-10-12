<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:wizard --try on a small table of its own: what it creates, how many documents, and the sample searches it runs. */
final class WizardTryCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->dropObjects();
        $connection = $this->context->connection;
        $connection->execute('CREATE TABLE fz_wiz_item (id bigint PRIMARY KEY, title text NOT NULL)');
        for ($id = 1; $id <= 7; ++$id) {
            $connection->execute('INSERT INTO fz_wiz_item (id, title) VALUES (:id, :title)', ['id' => $id, 'title' => 'Widget number ' . $id]);
        }
        $this->tester = new CommandTester(new WizardCommand(new PostgresIntrospector($connection), $this->context->engine, $connection));
    }

    protected function tearDown(): void
    {
        $this->dropObjects();
    }

    public function testTryAppliesTheSchemaAndReportsTheDocumentsAndTheDoctor(): void
    {
        $status = $this->tester->execute(['table' => 'fz_wiz_item', '--try' => true], ['interactive' => false, 'decorated' => true]);

        $display = $this->tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertNotNull($this->context->connection->fetchValue("SELECT to_regclass('fuzzphony_fz_wiz_items')"), 'the --try run created the index table');
        self::assertMatchesRegularExpression('/  7 documents, doctor: \e\[32mok\e\[39m/', $display, 'a healthy index is shown in the "info" colour');
        $note = preg_replace(['/\e\[[0-9;]*[A-Za-z]/', '/\s+(!\s+)?/'], ['', ' '], $display) ?? '';
        self::assertStringContainsString('The index "fz_wiz_items" stays in the database. Remove it with: fuzzphony:schema fz_wiz_items --drop --apply (after adding it to the config).', $note);
        self::assertStringNotContainsString('Next: add it to your configuration', $display);
    }

    public function testSampleSearchesShowTheFirstFiveIdsAndWhetherTypoToleranceWasUsed(): void
    {
        $this->tester->setInputs(['', 'widget', 'widgt', '']); // all fields, an exact search, a misspelt one, done

        $status = $this->tester->execute(['table' => 'fz_wiz_item', '--try' => true], ['interactive' => true]);

        $display = $this->tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame(2, preg_match_all('/^  7 hit\(s\), [\d.]+ ms(, typo tolerant)?: ids ([\d, ]+)$/m', $display, $searches), $display);
        self::assertSame(['', ', typo tolerant'], $searches[1], 'only the misspelt search needed typo tolerance, and it came second');
        foreach ($searches[2] as $ids) {
            self::assertCount(5, explode(', ', $ids), 'five ids of the seven hits');
        }
    }

    private function dropObjects(): void
    {
        $connection = $this->context->connection;
        $connection->execute('DROP TABLE IF EXISTS fz_wiz_item CASCADE');
        foreach (['fuzzphony_fz_wiz_items', 'fuzzphony_fz_wiz_items__vocab', 'fuzzphony_fz_wiz_items__next'] as $table) {
            $connection->execute(sprintf('DROP TABLE IF EXISTS "%s" CASCADE', $table));
        }
    }
}
