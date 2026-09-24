<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Regression test: SearchCommand's "-p|--profile" option used to collide with
 * Symfony\Bundle\FrameworkBundle\Console\Application's own global "--profile" run-profiling flag,
 * added to every console app's input definition regardless of which commands it runs. That flag is
 * merged into a command's own definition only when the command actually runs under a real
 * FrameworkBundle console Application (Command::mergeApplicationDefinition()) — a bare CommandTester
 * against the Command in isolation never merges it, so it never surfaced there. Every bundle command
 * is checked here, not just SearchCommand, so the same class of bug can't reappear unnoticed elsewhere.
 */
final class FrameworkBundleOptionCollisionTest extends TestCase
{
    /** @return iterable<string, array{0: Command}> */
    public static function commands(): iterable
    {
        $fuzzphony = new Fuzzphony(self::createStub(Engine::class), new IndexRegistry());
        $connection = self::createStub(Connection::class);

        yield 'schema' => [new SchemaCommand($fuzzphony, $connection)];
        yield 'doctor' => [new DoctorCommand($fuzzphony)];
        yield 'reindex' => [new ReindexCommand($fuzzphony)];
        yield 'worker' => [new WorkerCommand($fuzzphony)];
        yield 'search' => [new SearchCommand($fuzzphony)];
        yield 'wizard' => [new WizardCommand(self::createStub(SourceIntrospector::class), self::createStub(Engine::class), $connection)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commands')]
    public function testCommandOptionsDoNotCollideWithAFrameworkBundleConsoleApplication(Command $command): void
    {
        $kernel = self::createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('test');
        $application = new Application($kernel);

        $command->setApplication($application);
        // Throws "An option named '...' already exists." if the command redefines a reserved,
        // application-level option name or shortcut (e.g. --profile, -e/--env, --no-debug).
        $command->mergeApplicationDefinition();

        // "env" has been an application-level option for every supported FrameworkBundle version (unlike
        // "profile", which older versions lack), so it proves the merge actually happened, not that it was skipped.
        self::assertTrue($command->getDefinition()->hasOption('env'), 'the application definition must have been merged into the command');
    }
}
