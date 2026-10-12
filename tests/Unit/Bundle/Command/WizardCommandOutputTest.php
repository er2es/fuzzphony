<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Fuzzphony\Core\Wizard\TableProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** What fuzzphony:wizard prints around the suggestion: the table picker's default, the explanation table, the notes, the headings. */
final class WizardCommandOutputTest extends TestCase
{
    public function testEnterPicksTheFirstTable(): void
    {
        $tables = [['table' => 'fz_widget', 'rows' => 10], ['table' => 'fz_gadget', 'rows' => 2000]];
        $tester = $this->tester($tables);
        $tester->setInputs(['', '']); // the default table, then all fields

        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay(true));
        self::assertStringContainsString('Which table should be searchable?', $tester->getDisplay(true));
        self::assertStringContainsString('fz_gadget (~2,000 rows)', $tester->getDisplay(true));
        self::assertStringContainsString('Suggested index for "fz_widget" (~100 rows)', $tester->getDisplay(true));
    }

    public function testTheDecisionsAreExplainedInATableAndTheNotesAreListed(): void
    {
        $tester = $this->tester([], notes: ['Sampled 100 rows.', 'No statistics yet.']);

        $tester->execute(['table' => 'fz_gadget'], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertMatchesRegularExpression('/^ +Column +Role +Why +$/m', $display);
        self::assertMatchesRegularExpression('/^ +id +id +primary key \(bigint\) +$/m', $display);
        self::assertMatchesRegularExpression('/^ +title +field +/m', $display);
        self::assertMatchesRegularExpression('/^ +description +field +/m', $display);
        self::assertStringContainsString('! [NOTE] Sampled 100 rows.', $display);
        self::assertStringContainsString('! [NOTE] No statistics yet.', $display);
    }

    public function testTheNameOptionNamesTheIndex(): void
    {
        $named = $this->tester([]);
        $named->execute(['table' => 'fz_gadget', '--name' => 'gizmos'], ['interactive' => false]);
        $derived = $this->tester([]);
        $derived->execute(['table' => 'fz_gadget'], ['interactive' => false]);

        self::assertStringContainsString("gizmos:\n", $named->getDisplay(true));
        self::assertStringNotContainsString('gizmos', $derived->getDisplay(true));
    }

    public function testTheFieldsPromptKeepsTheChosenOnes(): void
    {
        $tester = $this->tester([]);
        $tester->setInputs(['1']); // only the description

        $status = $tester->execute(['table' => 'fz_gadget'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('description:', $tester->getDisplay(true));
        self::assertStringNotContainsString("title:\n", $tester->getDisplay(true));
        self::assertStringNotContainsString('        title', $tester->getDisplay(true));
    }

    public function testNonInteractiveRunsDoNotAskAnything(): void
    {
        $tester = $this->tester([]);

        $status = $tester->execute(['table' => 'fz_gadget'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Searchable fields to keep', $tester->getDisplay(true));
        self::assertStringContainsString('title:', $tester->getDisplay(true));
        self::assertStringContainsString('description:', $tester->getDisplay(true));
    }

    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'yaml' => ['yaml', 'config/packages/fuzzphony.yaml'];
        yield 'builder' => ['builder', 'Builder'];
        yield 'attributes' => ['attributes', 'Attributes'];
    }

    #[DataProvider('formats')]
    public function testTheCodeIsIntroducedByItsHeadingAndFollowedByTheNextSteps(string $format, string $heading): void
    {
        $tester = $this->tester([]);

        $tester->execute(['table' => 'fz_gadget', '--format' => $format], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString("\n" . $heading . "\n" . str_repeat('-', strlen($heading)) . "\n", $display);
        self::assertStringContainsString('Next: add it to your configuration, then fuzzphony:schema --apply, fuzzphony:reindex, fuzzphony:doctor. Or re-run with --try.', $display);
    }

    /**
     * @param list<array{table: string, rows: int}> $tables
     * @param list<string>                          $notes
     */
    private function tester(array $tables, array $notes = []): CommandTester
    {
        $introspector = self::createStub(SourceIntrospector::class);
        $introspector->method('tables')->willReturn($tables);
        $introspector->method('describe')->willReturnCallback(static fn(string $table): TableProfile => new TableProfile($table, [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('title', ColumnKind::Text, 'text', averageLength: 20.0),
            new ColumnProfile('description', ColumnKind::Text, 'text', averageLength: 500.0),
        ], 'id', estimatedRows: 100, notes: $notes));

        return new CommandTester(new WizardCommand($introspector, self::createStub(Engine::class), self::createStub(Connection::class)));
    }
}
