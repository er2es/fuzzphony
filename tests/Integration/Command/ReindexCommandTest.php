<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Core\Support\Coerce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReindexCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->context->applySchemaAndReindex();
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: document 4 is now an orphan
        $this->tester = new CommandTester(new ReindexCommand($this->context->fuzzphony));
    }

    public function testAFullRunRemovesOrphansAndSaysHowMany(): void
    {
        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('1 orphaned document(s) removed', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testAResumedRunLeavesOrphansAlone(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--from' => '2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('only removed by a full run', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed());
    }

    private function indexed(): int
    {
        return Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM fuzzphony_products'));
    }
}
