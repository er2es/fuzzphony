<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * fuzzphony:analytics against a search-log store that records what it is asked (no database):
 * the validation messages, the --since window, the tenant hand-over and the exact JSON / empty-table output.
 */
final class AnalyticsCommandMutationsTest extends TestCase
{
    /** @var list<array{string, \DateTimeInterface, int, int|string|null, bool}> the (report, from, limit, tenant, allTenants) of every store call */
    private array $calls = [];

    /** @var list<QueryStat> */
    private array $stats = [];

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function usageErrors(): iterable
    {
        yield 'top and zero together' => [['--top' => true, '--zero' => true], 'Choose --top or --zero, not both.'];
        yield 'an empty tenant' => [['--tenant' => ''], '--tenant needs a value.'];
        yield 'a tenant and all tenants together' => [['--tenant' => '1', '--all-tenants' => true], 'Choose --tenant or --all-tenants, not both.'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('usageErrors')]
    public function testEachUsageErrorSaysWhatIsWrong(array $options, string $message): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', ...$options], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString($message, $this->flat($tester));
        self::assertSame([], $this->calls, 'the store was not asked');
    }

    /** @return iterable<string, array{string, string}> */
    public static function windows(): iterable
    {
        yield 'hours' => ['36h', 'PT36H'];
        yield 'days' => ['10d', 'P10D'];
        yield 'weeks' => ['3w', 'P3W'];
    }

    #[DataProvider('windows')]
    public function testSinceIsANumberOfHoursDaysOrWeeksBackFromNow(string $since, string $interval): void
    {
        $tester = $this->tester();
        $before = new \DateTimeImmutable();

        $tester->execute(['index' => 'products', '--since' => $since], ['interactive' => false]);

        $after = new \DateTimeImmutable();
        self::assertCount(1, $this->calls);
        $from = $this->calls[0][1];
        self::assertGreaterThanOrEqual($before->sub(new \DateInterval($interval))->getTimestamp(), $from->getTimestamp());
        self::assertLessThanOrEqual($after->sub(new \DateInterval($interval))->getTimestamp(), $from->getTimestamp());
    }

    public function testASinceWithATrailingNewlineIsNotAWindow(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--since' => "7d\n"], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Cannot parse --since', $tester->getDisplay(true));
        self::assertSame([], $this->calls);
    }

    public function testTheTenantOfAZeroResultReportIsHandedOverAsTyped(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--zero' => true, '--tenant' => '12'], ['interactive' => false]);
        $tester->execute(['index' => 'products', '--zero' => true, '--all-tenants' => true], ['interactive' => false]);
        $tester->execute(['index' => 'products', '--zero' => true], ['interactive' => false]);

        self::assertCount(3, $this->calls);
        self::assertSame(['zero', '12', false], [$this->calls[0][0], $this->calls[0][3], $this->calls[0][4]], 'the raw string');
        self::assertSame(['zero', null, true], [$this->calls[1][0], $this->calls[1][3], $this->calls[1][4]]);
        self::assertSame(['zero', null, false], [$this->calls[2][0], $this->calls[2][3], $this->calls[2][4]]);
    }

    public function testTheTenantOfATopReportIsHandedOverAsTyped(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--tenant' => 'acme'], ['interactive' => false]);

        self::assertSame(['top', 'acme', false], [$this->calls[0][0], $this->calls[0][3], $this->calls[0][4]]);
    }

    public function testTheJsonKeepsUnicodeAndSlashesReadableAndIsPrettyPrinted(): void
    {
        $this->stats = [new QueryStat('árvíztűrő a/b', 3, 2.5, new \DateTimeImmutable('2026-10-12 10:00:00 UTC'))];
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--format' => 'json'], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString('"query": "árvíztűrő a/b"', $display, 'neither \u escapes nor \/ escapes');
        self::assertStringContainsString("[\n    {\n        \"query\"", $display, 'pretty printed');
    }

    public function testAQueryThatIsNotValidUtf8IsAnErrorNotAnEmptyReport(): void
    {
        $this->stats = [new QueryStat("caf\xE9", 1, 1.0, new \DateTimeImmutable('2026-10-12 10:00:00 UTC'))];
        $tester = $this->tester();

        $this->expectException(\JsonException::class);

        $tester->execute(['index' => 'products', '--format' => 'json'], ['interactive' => false]);
    }

    public function testAnEmptyTableReportSaysSoInsteadOfPrintingAnEmptyTable(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--since' => '12h'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('No searches in the last 12h.', $display);
        self::assertStringNotContainsString('avg hits', $display, 'no table header');
    }

    /** The message is wrapped in a block: compare it without the line breaks and the block's margin. */
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay(true));
    }

    private function tester(): CommandTester
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, SearchLogStore::class]);
        $engine->method('topQueries')->willReturnCallback(function (IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array {
            $this->calls[] = ['top', $from, $limit, $tenant, $allTenants];

            return $this->stats;
        });
        $engine->method('zeroResultQueries')->willReturnCallback(function (IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array {
            $this->calls[] = ['zero', $from, $limit, $tenant, $allTenants];

            return $this->stats;
        });
        assert($engine instanceof Engine);

        return new CommandTester(new AnalyticsCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]))));
    }
}
