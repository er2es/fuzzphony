<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandMutationsTest extends TestCase
{
    /** An idle worker waits a second between polls by default: without it a long-running worker would spin the CPU. */
    public function testTheWorkerSleepsASecondBetweenIdlePollsByDefault(): void
    {
        $command = new WorkerCommand(new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products('queue')])));

        self::assertSame(1.0, (new \ReflectionProperty(WorkerCommand::class, 'idleSleep'))->getValue($command));
        self::assertSame(500, (new \ReflectionProperty(WorkerCommand::class, 'batchSize'))->getValue($command));
    }

    public function testOnlyTheQueueModeIndexesAreWorkedOffEvenWhenAManualOneComesFirst(): void
    {
        $seen = [];
        $engine = self::createStub(Engine::class);
        $engine->method('processQueue')->willReturnCallback(static function (IndexDefinition $index, int $limit) use (&$seen): int {
            $seen[] = $index->name;

            return 0;
        });
        $manual = IndexDefinition::builder('shirts')->fromQuery('SELECT id, name FROM fz_shirt')->sync('manual')->field('name', 'A')->build();
        $tester = new CommandTester(new WorkerCommand(new Fuzzphony($engine, new IndexRegistry([$manual, Indexes::products('queue')]))));

        $status = $tester->execute(['--once' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['products'], $seen);
    }
}
