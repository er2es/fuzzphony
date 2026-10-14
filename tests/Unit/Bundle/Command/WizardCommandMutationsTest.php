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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WizardCommandMutationsTest extends TestCase
{
    public function testKeepingOnlyTheSecondFieldExportsAListWithThatFieldAlone(): void
    {
        $introspector = self::createStub(SourceIntrospector::class);
        $introspector->method('describe')->willReturn(new TableProfile('fz_gadget', [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('title', ColumnKind::Text, 'text', averageLength: 20.0),
            new ColumnProfile('description', ColumnKind::Text, 'text', averageLength: 500.0),
        ], 'id', estimatedRows: 100));
        $tester = new CommandTester(new WizardCommand($introspector, self::createStub(Engine::class), self::createStub(Connection::class)));
        $tester->setInputs(['1']); // keep only the second field (description)

        $status = $tester->execute(['table' => 'fz_gadget', '--format' => 'builder'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay(true);
        self::assertStringContainsString("->field('description'", $display);
        self::assertStringNotContainsString("->field('title'", $display);
    }
}
