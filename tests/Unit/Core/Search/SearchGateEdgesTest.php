<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The corners of the gate that decide a tenant comparison, a log row and the fields a viewer keeps. */
final class SearchGateEdgesTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed, bool}> */
    public static function tenants(): iterable
    {
        $text = static fn(string $s): object => new class ($s) implements \Stringable {
            public function __construct(private string $s) {}

            public function __toString(): string
            {
                return $this->s;
            }
        };

        yield 'int and its string' => [3, '3', true];
        yield 'string and its int' => ['3', 3, true];
        yield 'two different ints' => [3, 4, false];
        yield 'stringable and string' => [$text('x'), 'x', true];
        yield 'string and stringable' => ['x', $text('x'), true];
        yield 'two stringables' => [$text('x'), $text('x'), true];
        yield 'int and an array' => [3, [3], false];
        yield 'empty string and null' => ['', null, false];
        yield 'null and empty string' => [null, '', false];
        yield 'two nulls' => [null, null, true];
        yield 'equal arrays' => [['a'], ['a'], true];
    }

    #[DataProvider('tenants')]
    public function testTwoTenantsAreTheSameWhenTheirTextIs(mixed $a, mixed $b, bool $same): void
    {
        $method = new \ReflectionMethod(SearchGate::class, 'sameTenant');

        self::assertSame($same, $method->invoke(null, $a, $b));
    }

    public function testTheFieldsOfTheQueryAreAListWhateverTheGuardReturns(): void
    {
        $seen = null;
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(static function ($index, SearchQuery $q) use (&$seen): SearchResult {
            $seen = $q->allowedFields;

            return SearchResult::empty(20, 0);
        });
        $guard = new class implements SearchGuard {
            public function check(\Fuzzphony\Core\Definition\IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return Access::only(['name', 'brand']);
            }
        };
        $gate = new SearchGate(guards: [$guard]);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada'))->restrictedTo(['zzz', 'name', 'yyy', 'brand']);

        $gate->search($engine, Indexes::products(), $query);

        self::assertSame(['name', 'brand'], $seen);
    }

    public function testLoggingASystemSearchIsRefusedWhereSystemSearchesAreSwitchedOff(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log, allowSystem: false);

        try {
            $gate->logSearch(Indexes::products(), (new SearchQuery('mouse'))->asSystem(), 1, 1.0, false, false);
            self::fail('a system search was logged');
        } catch (AccessDenied) {
            self::assertSame([], $log->records);
        }
    }

    public function testAnIndexWithATenantCannotBeLoggedWithoutOne(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada'));

        try {
            $gate->logSearch(Indexes::products('queue', tenant: true), $query, 1, 1.0, false, false);
            self::fail('a search without a tenant was logged');
        } catch (ActorRequired) {
            self::assertSame([], $log->records);
        }
    }

    public function testAnIndexCannotBeLoggedWhereNoLogIsConfigured(): void
    {
        $this->expectException(InvalidArgument::class);
        (new SearchGate())->logSearch(Indexes::products(), (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada')), 1, 1.0, false, false);
    }

    public function testALogThatFailsNeverFailsTheCallerAndIsCounted(): void
    {
        $log = new class implements SearchLog {
            public function record(SearchRecord $record): void
            {
                throw new \RuntimeException('log store is down');
            }
        };
        $metrics = new class implements MetricsCollector {
            /** @var list<string> */
            public array $events = [];

            public function increment(string $event, array $labels = [], int $by = 1): void
            {
                $this->events[] = $event;
                $this->labels[] = $labels;
            }

            public function observe(string $event, float $value, array $labels = []): void {}

            public function gauge(string $event, float $value, array $labels = []): void {}

            /** @var list<array<string, string>> */
            public array $labels = [];
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('could not record'),
            ['message' => 'log store is down', 'index' => 'products'],
        );
        $gate = new SearchGate(metrics: $metrics, logger: $logger, log: $log);

        $logged = $gate->logSearch(Indexes::products(), (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada')), 3, 2.0, false, false);

        self::assertSame(3, $logged->hits);
        self::assertSame(['fuzzphony.search_log.failed'], $metrics->events);
        self::assertSame([['index' => 'products']], $metrics->labels);
    }

    public function testALogThatFailsIsSurvivedWithoutALogger(): void
    {
        $log = new class implements SearchLog {
            public function record(SearchRecord $record): void
            {
                throw new \RuntimeException('log store is down');
            }
        };
        $gate = new SearchGate(log: $log);

        $logged = $gate->logSearch(Indexes::products(), (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada')), 3, 2.0, false, false);

        self::assertSame(3, $logged->hits);
    }

    public function testTheTenantTheCallerNamedIsLoggedBeforeTheActorsOnAnUnscopedIndex(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(tenant: 3, user: 'ada'))->forTenant(9);

        $gate->logSearch(Indexes::products(), $query, 1, 1.0, false, false);

        self::assertSame(9, $log->records[0]->tenant);
    }

    public function testTheLoggedCountsAndTimesAreNeverNegativeNorRounded(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada'));

        $gate->logSearch(Indexes::products(), $query, -5, -2.5, false, false);
        $gate->logSearch(Indexes::products(), $query, 0, 0.5, false, false);
        $gate->logSearch(Indexes::products(), $query, 7, 12.25, false, false);

        self::assertSame([[0, 0.0], [0, 0.5], [7, 12.25]], array_map(static fn($r): array => [$r->hits, $r->tookMs], $log->records));
    }

    public function testARefusedSearchIsNotRecordedAsRescued(): void
    {
        $log = new RecordingSearchLog();
        $engine = self::createStub(Engine::class);
        $gate = new SearchGate(log: $log);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(tenant: 3, user: 'ada'))->forTenant(9);

        try {
            $gate->search($engine, Indexes::products(tenant: true), $query);
            self::fail('crossed');
        } catch (AccessDenied) {
            self::assertFalse($log->records[0]->rescued);
        }
    }
}
