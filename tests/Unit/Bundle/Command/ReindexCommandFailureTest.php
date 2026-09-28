<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:reindex when a run fails: the error, how to resume, a non-zero exit. */
final class ReindexCommandFailureTest extends TestCase
{
    public function testASwapThatKeepsFailingPrintsHowToResumeAndFails(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('beginRebuild')->willReturn(true);
        $engine->method('sourceIds')->willReturnOnConsecutiveCalls([1, 2], [3]);
        $engine->method('refreshShadow')->willReturnOnConsecutiveCalls(2, 1);
        $engine->expects(self::once())->method('finishRebuild')->willThrowException(new EngineFailure('the swap could not lock "public"."fuzzphony_products" within 3s, 6 times. The rebuild was kept.'));
        $engine->expects(self::once())->method('abortRebuild')->with(self::anything(), true);
        $engine->expects(self::never())->method('recordReindex');
        $tester = $this->tester($engine);

        $status = $tester->execute(['--batch' => '2'], ['interactive' => false]);

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $status, $display);
        self::assertStringContainsString('the swap could not lock "public"."fuzzphony_products" within 3s, 6 times. The rebuild was kept.', $display);
        self::assertStringContainsString('  Resume it: bin/console fuzzphony:reindex products --from=3', $display);
        self::assertStringNotContainsString('Done.', $display);
        self::assertStringNotContainsString('documents in', $display);
    }

    public function testAFailureBeforeTheFirstBatchHasNothingToResume(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('beginRebuild')->willThrowException(new InvalidArgument('A rebuild of "products" is already running.'));
        $tester = $this->tester($engine);

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('A rebuild of "products" is already running.', $tester->getDisplay());
        self::assertStringNotContainsString('Resume it', $tester->getDisplay());
    }

    public function testAnotherFailureIsNotCaught(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('beginRebuild')->willThrowException(new \LogicException('a bug'));

        $this->expectExceptionObject(new \LogicException('a bug'));

        $this->tester($engine)->execute([], ['interactive' => false]);
    }

    private function tester(Engine $engine): CommandTester
    {
        return new CommandTester(new ReindexCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products()]))));
    }
}
