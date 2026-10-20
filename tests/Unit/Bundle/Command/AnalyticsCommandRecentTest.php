<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\RecentSearchStore;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:analytics --recent against a store that records what it is asked: the options, the hand-over and the exact output. */
final class AnalyticsCommandRecentTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<LoggedSearch> */
    private array $logged = [];

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function usageErrors(): iterable
    {
        yield 'recent with top' => [['--recent' => true, '--top' => true], 'Choose --top, --zero or --recent, not several.'];
        yield 'recent with zero' => [['--recent' => true, '--zero' => true], 'Choose --top, --zero or --recent, not several.'];
        yield 'user without recent' => [['--user' => 'ada'], '--user, --anonymous and --status go with --recent.'];
        yield 'anonymous without recent' => [['--anonymous' => true], '--user, --anonymous and --status go with --recent.'];
        yield 'status without recent' => [['--status' => 'ok'], '--user, --anonymous and --status go with --recent.'];
        yield 'an empty user' => [['--recent' => true, '--user' => ''], '--user needs a value.'];
        yield 'user and anonymous' => [['--recent' => true, '--user' => 'ada', '--anonymous' => true], 'Choose --user or --anonymous, not both.'];
        yield 'an unknown status' => [['--recent' => true, '--status' => 'blocked'], '--status must be ok, denied or rate_limited, got "blocked".'];
        yield 'a tenant and all tenants' => [['--recent' => true, '--tenant' => '1', '--all-tenants' => true], 'Choose --tenant or --all-tenants, not both.'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('usageErrors')]
    public function testEachUsageErrorSaysWhatIsWrongAndAsksNothing(array $options, string $message): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', ...$options], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString($message, (string) preg_replace('/\s+/', ' ', $tester->getDisplay(true)));
        self::assertSame([], $this->calls, 'the store was not asked');
    }

    public function testTheOptionsAreHandedOverAsTyped(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true, '--user' => '007', '--tenant' => '12', '--status' => 'denied', '--limit' => '5', '--since' => '36h'], ['interactive' => false]);
        $tester->execute(['index' => 'products', '--recent' => true, '--anonymous' => true, '--all-tenants' => true], ['interactive' => false]);
        $tester->execute(['index' => 'products', '--recent' => true], ['interactive' => false]);

        self::assertCount(3, $this->calls);
        self::assertSame(['recent', 5, '12', false, '007', false, RecordStatus::Denied], self::args($this->calls[0]), 'the raw strings');
        self::assertSame(['recent', 20, null, true, null, true, null], self::args($this->calls[1]));
        self::assertSame(['recent', 20, null, false, null, false, null], self::args($this->calls[2]));
        $from = $this->calls[0]['from'];
        self::assertInstanceOf(\DateTimeInterface::class, $from);
        self::assertEqualsWithDelta(time() - 36 * 3600, $from->getTimestamp(), 5);
    }

    public function testTheJsonHasEveryFieldAndKeepsNullsAndUnicode(): void
    {
        $this->logged = [
            new LoggedSearch(new \DateTimeImmutable('2026-10-12 10:00:00 UTC'), 'products', 'acme', 'ada', '203.0.113.0', 'árvíztűrő a/b', 3, 12.5, true, RecordStatus::Ok),
            new LoggedSearch(new \DateTimeImmutable('2026-10-12 09:00:00 UTC'), 'products', null, null, null, 'x', 0, 0.0, false, RecordStatus::RateLimited),
        ];
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true, '--format' => 'json'], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString('"query": "árvíztűrő a/b"', $display);
        $rows = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['at' => '2026-10-12T10:00:00+00:00', 'index' => 'products', 'tenant' => 'acme', 'user' => 'ada', 'ip' => '203.0.113.0', 'query' => 'árvíztűrő a/b', 'hits' => 3, 'took_ms' => 12.5, 'used_fuzzy' => true, 'status' => 'ok'],
            ['at' => '2026-10-12T09:00:00+00:00', 'index' => 'products', 'tenant' => null, 'user' => null, 'ip' => null, 'query' => 'x', 'hits' => 0, 'took_ms' => 0, 'used_fuzzy' => false, 'status' => 'rate_limited'],
        ], $rows);
    }

    public function testTheTableShowsTheTenantOnlyWhenNoneWasNamed(): void
    {
        $this->logged = [new LoggedSearch(new \DateTimeImmutable('2026-10-12 10:00:00 UTC'), 'products', 'acme', null, null, 'mouse', 3, 1.0, false, RecordStatus::Denied)];
        $any = $this->tester();
        $one = $this->tester();

        $any->execute(['index' => 'products', '--recent' => true, '--all-tenants' => true], ['interactive' => false]);
        $one->execute(['index' => 'products', '--recent' => true, '--tenant' => 'acme'], ['interactive' => false]);

        self::assertMatchesRegularExpression('/time\s+tenant\s+user\s+ip\s+status\s+hits\s+query/', $any->getDisplay(true));
        self::assertMatchesRegularExpression('/acme\s+\(anonymous\)\s+-\s+denied\s+3\s+mouse/', $any->getDisplay(true));
        self::assertMatchesRegularExpression('/time\s+user\s+ip\s+status\s+hits\s+query/', $one->getDisplay(true));
        self::assertDoesNotMatchRegularExpression('/tenant/', $one->getDisplay(true));
    }

    public function testTextThatLooksLikeConsoleMarkupIsPrintedAsText(): void
    {
        $this->logged = [new LoggedSearch(new \DateTimeImmutable('2026-10-12 10:00:00 UTC'), 'products', null, '<error>ada</error>', '<info>1</info>', '<comment>mouse</comment>', 1, 1.0, false, RecordStatus::Ok)];
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString('<error>ada</error>', $display);
        self::assertStringContainsString('<info>1</info>', $display);
        self::assertStringContainsString('<comment>mouse</comment>', $display);
    }

    public function testControlCharactersAreNeverPrintedToTheTerminal(): void
    {
        $this->logged = [new LoggedSearch(
            new \DateTimeImmutable('2026-10-12 10:00:00 UTC'),
            'products',
            "ac\tme",
            "ada\e[2J",
            "1.2\r.3",
            "line one\nfake row\e[31mred\e[0m \x07bell \x7f del \u{85} c1 caf\xE9",
            1,
            1.0,
            false,
            RecordStatus::Ok,
        )];
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true, '--all-tenants' => true], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertSame(0, preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $display), 'no control byte but the newlines of the table');
        self::assertStringNotContainsString("\u{85}", $display);
        self::assertStringContainsString("line one\u{FFFD}fake row\u{FFFD}[31mred\u{FFFD}[0m \u{FFFD}bell \u{FFFD} del \u{FFFD} c1 caf", $display);
        self::assertStringContainsString("ada\u{FFFD}[2J", $display);
        self::assertStringContainsString("ac\u{FFFD}me", $display);
        self::assertStringContainsString("1.2\u{FFFD}.3", $display);
    }

    public function testTheJsonKeepsTheTextExactlyAndEscapesItAsJson(): void
    {
        $this->logged = [new LoggedSearch(new \DateTimeImmutable('2026-10-12 10:00:00 UTC'), 'products', null, null, null, "a\nb\e[31m", 1, 1.0, false, RecordStatus::Ok)];
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true, '--format' => 'json'], ['interactive' => false]);

        $rows = json_decode($tester->getDisplay(true), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame("a\nb\e[31m", $rows[0]['query']);
    }

    public function testAnEmptyTableSaysSoInsteadOfPrintingAnEmptyTable(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['index' => 'products', '--recent' => true, '--since' => '12h'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No searches in the last 12h.', $tester->getDisplay(true));
        self::assertStringNotContainsString('query', $tester->getDisplay(true), 'no table header');
    }

    public function testAnEmptyJsonReportIsAnEmptyList(): void
    {
        $tester = $this->tester();

        $tester->execute(['index' => 'products', '--recent' => true, '--format' => 'json'], ['interactive' => false]);

        self::assertSame('[]', trim($tester->getDisplay(true)));
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return list<mixed>
     */
    private static function args(array $call): array
    {
        return [$call['report'], $call['limit'], $call['tenant'], $call['allTenants'], $call['user'], $call['anonymousOnly'], $call['status']];
    }

    private function tester(): CommandTester
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, SearchLogStore::class, RecentSearchStore::class]);
        $engine->method('recentSearches')->willReturnCallback(function (IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array {
            $this->calls[] = ['report' => 'recent', 'from' => $from, 'limit' => $limit, 'tenant' => $tenant, 'allTenants' => $allTenants, 'user' => $user, 'anonymousOnly' => $anonymousOnly, 'status' => $status];

            return $this->logged;
        });
        assert($engine instanceof Engine);

        return new CommandTester(new AnalyticsCommand(new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]))));
    }
}
