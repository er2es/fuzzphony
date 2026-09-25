<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\ForeignKey;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Fuzzphony\Core\Wizard\TableProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Scenarios that only need the SourceIntrospector (no real Postgres): the table-picker prompt,
 * suggestions with nothing usable, the fields-to-keep prompt and the attributes/YAML fallback.
 * The --try flow (real schema, reindex and search) is covered against a real database in
 * tests/Integration/Command/WizardCommandTest.php.
 */
final class WizardCommandTest extends TestCase
{
    public function testNoTablesFoundIsAFailure(): void
    {
        $tester = new CommandTester($this->command($this->introspector([])));

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No tables found.', $tester->getDisplay());
    }

    public function testInteractiveTablePickerAsksWhenNoArgumentIsGiven(): void
    {
        $tables = [
            ['table' => 'fz_widget', 'rows' => 10],
            ['table' => 'fz_gadget', 'rows' => 20],
        ];
        $tester = new CommandTester($this->command($this->introspector($tables, $this->twoFieldProfile('fz_gadget'))));
        // "1" picks the second table choice (fz_gadget); "" accepts all fields in the follow-up prompt.
        $tester->setInputs(['1', '']);

        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Suggested index for "fz_gadget"', $tester->getDisplay());
    }

    public function testNoUsableSuggestionIsAFailure(): void
    {
        $profile = new TableProfile('fz_empty', [new ColumnProfile('id', ColumnKind::Int, 'bigint')], 'id', estimatedRows: 3);
        $tester = new CommandTester($this->command($this->introspector([], $profile)));

        $status = $tester->execute(['table' => 'fz_empty'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No usable index could be suggested', $tester->getDisplay());
    }

    public function testInteractiveFieldsKeepPromptNarrowsTheSuggestion(): void
    {
        $tester = new CommandTester($this->command($this->introspector([], $this->twoFieldProfile('fz_gadget'))));
        $tester->setInputs(['0']); // keep only the first field (title)

        $status = $tester->execute(['table' => 'fz_gadget'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('title:', $display);
        self::assertStringNotContainsString('description:', $display, 'the unkept field must not appear in the exported definition');
    }

    public function testAttributesFormatFallsBackToYamlForAJoinedSource(): void
    {
        $tester = new CommandTester($this->command($this->introspector([], $this->joinedProfile())));

        $status = $tester->execute(['table' => 'fz_order', '--format' => 'attributes'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        // SymfonyStyle::warning() word-wraps its block, so check the fragments rather than one long string.
        self::assertStringContainsString('Joined sources cannot be expressed with attributes; showing YAML', $display);
        self::assertStringContainsString('instead.', $display);
        self::assertStringContainsString('config/packages/fuzzphony.yaml', $display);
    }

    public function testAttributesFormatIsExportedForAPlainTableSource(): void
    {
        $tester = new CommandTester($this->command($this->introspector([], $this->twoFieldProfile('fz_gadget'))));

        $status = $tester->execute(['table' => 'fz_gadget', '--format' => 'attributes'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('#[Searchable(', $display);
        self::assertStringContainsString('final class Fz_gadget', $display);
    }

    public function testCompletesTableNamesAndFormats(): void
    {
        $tables = [['table' => 'fz_widget', 'rows' => 1], ['table' => 'fz_gadget', 'rows' => 2]];
        $completion = new CommandCompletionTester($this->command($this->introspector($tables)));

        self::assertSame(['fz_widget', 'fz_gadget'], $completion->complete(['']));
        self::assertSame(['yaml', 'builder', 'attributes'], $completion->complete(['--format', '']));
    }

    /** @param list<array{table: string, rows: int}> $tables */
    private function introspector(array $tables, ?TableProfile $profile = null): SourceIntrospector
    {
        $introspector = self::createStub(SourceIntrospector::class);
        $introspector->method('tables')->willReturn($tables);
        if ($profile !== null) {
            $introspector->method('describe')->willReturn($profile);
        }

        return $introspector;
    }

    private function command(SourceIntrospector $introspector): WizardCommand
    {
        return new WizardCommand($introspector, self::createStub(Engine::class), self::createStub(Connection::class));
    }

    /** A plain table with two searchable text columns and no relations: title (A) and description (D). */
    private function twoFieldProfile(string $table): TableProfile
    {
        return new TableProfile($table, [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('title', ColumnKind::Text, 'text', averageLength: 20.0),
            new ColumnProfile('description', ColumnKind::Text, 'text', averageLength: 500.0),
        ], 'id', estimatedRows: 100);
    }

    /** A table whose only searchable field comes from a joined table's label column. */
    private function joinedProfile(): TableProfile
    {
        $customers = new TableProfile('customers', [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('name', ColumnKind::Text, 'text', averageLength: 20.0),
        ], 'id', estimatedRows: 50);

        return new TableProfile('fz_order', [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('customer_id', ColumnKind::Int, 'bigint'),
        ], 'id', estimatedRows: 100, foreignKeys: [
            new ForeignKey('customer_id', 'customers', 'id', $customers),
        ]);
    }
}
