<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Analytics\QueryRedactor;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

final class SearchGateLogTest extends TestCase
{
    public function testASuccessfulSearchIsRecordedWithItsActor(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log);
        $builder = new SearchBuilder($this->engineReturning(total: 3, tookMs: 4.2, usedFuzzy: true), Indexes::products(tenant: true), gate: $gate);

        $builder->query('Wireless Mouse')->asActor(new Actor(tenant: 3, user: 'ada'))->get();

        self::assertCount(1, $log->records);
        $r = $log->records[0];
        self::assertSame(['products', 3, 'ada', 'Wireless Mouse', 3, 4.2, true, RecordStatus::Ok], [$r->index, $r->tenant, $r->user, $r->query, $r->hits, $r->tookMs, $r->usedFuzzy, $r->status]);
    }

    public function testASearchWithoutAnActorIsRecordedWithoutAUserAndWithTheQueryTenant(): void
    {
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(tenant: true), gate: new SearchGate(log: $log));

        $builder->query('mouse')->forTenant('acme')->get();

        self::assertCount(1, $log->records);
        self::assertSame(['acme', null], [$log->records[0]->tenant, $log->records[0]->user]);
    }

    public function testATenantThatIsNotAnIntOrAStringIsRecordedAsText(): void
    {
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(tenant: true), gate: new SearchGate(log: $log));
        $uuid = new class implements \Stringable {
            public function __toString(): string
            {
                return 'a-b-c';
            }
        };

        $builder->query('mouse')->forTenant($uuid)->get();
        $builder->query('mouse')->forTenant(1.5)->get();
        $builder->query('mouse')->forTenant(new \stdClass())->get();

        self::assertSame(['a-b-c', '1.5', null], array_map(static fn(SearchRecord $r): int|string|null => $r->tenant, $log->records));
    }

    public function testAnActorRefusalIsRecordedAsDenied(): void
    {
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder(self::createStub(Engine::class), Indexes::products(tenant: true), gate: new SearchGate(log: $log));

        try {
            $builder->query('x')->asActor(new Actor(user: 'ada'))->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }

        self::assertSame([RecordStatus::Denied], array_map(static fn(SearchRecord $r): RecordStatus => $r->status, $log->records));
        self::assertSame(['x', 'ada', 0, 0.0, false], [$log->records[0]->query, $log->records[0]->user, $log->records[0]->hits, $log->records[0]->tookMs, $log->records[0]->usedFuzzy]);
    }

    public function testExplainIsNotRecorded(): void
    {
        $log = new RecordingSearchLog();
        $engine = self::createStub(Engine::class);
        $engine->method('explain')->willReturn(new Explanation('mouse', [], [], SearchResult::empty(20, 0)));
        $builder = new SearchBuilder($engine, Indexes::products(), gate: new SearchGate(log: $log));

        $builder->query('mouse')->explain();

        self::assertSame([], $log->records);
    }

    public function testTheQueryIsRedactedBeforeItIsRecorded(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log, redactor: PatternQueryRedactor::fromSettings(['email']));
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: $gate);

        $builder->query('orders of ada@example.com')->get();

        self::assertSame('orders of [redacted]', $log->records[0]->query);
    }

    public function testTheRedactionHappensBeforeTheTextIsCutTo255Characters(): void
    {
        $log = new RecordingSearchLog();
        // 240 characters, then an address that would be cut in half at 255: redacted first, it is cut whole
        $text = str_repeat('a', 240) . ' ada@example.com';
        $gate = new SearchGate(log: $log, redactor: PatternQueryRedactor::fromSettings(['email']));
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: $gate);

        $builder->query($text)->get();

        self::assertSame(str_repeat('a', 240) . ' [redacted]', $log->records[0]->query);
    }

    public function testALongQueryIsCutTo255Characters(): void
    {
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate(log: $log));

        $builder->query(str_repeat('é', 400))->get();

        self::assertSame(str_repeat('é', 255), $log->records[0]->query);
    }

    public function testAnIndexWithLogFalseIsNotRecorded(): void
    {
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products()->withLog(false), gate: new SearchGate(log: $log));

        $builder->query('mouse')->get();

        self::assertSame([], $log->records);
    }

    public function testALogThatThrowsNeverFailsTheSearch(): void
    {
        $metrics = new RecordingMetricsCollector();
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not record'), self::callback(static fn(array $c): bool => $c['index'] === 'products' && $c['message'] === 'disk full'));
        $log = new class implements SearchLog {
            public function record(SearchRecord $record): void
            {
                throw new \RuntimeException('disk full');
            }
        };
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate($metrics, $logger, $log));

        $result = $builder->query('mouse')->get();

        self::assertSame(1, $result->total);
        self::assertSame([['increment', 'fuzzphony.search_log.failed', 1.0, ['index' => 'products']]], $metrics->calls);
    }

    public function testARedactorThatThrowsNeverFailsTheSearchEither(): void
    {
        $metrics = new RecordingMetricsCollector();
        $redactor = new class implements QueryRedactor {
            public function redact(string $query): string
            {
                throw new \RuntimeException('bad pattern');
            }
        };
        $log = new RecordingSearchLog();
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate($metrics, log: $log, redactor: $redactor));

        self::assertSame(1, $builder->query('mouse')->get()->total);
        self::assertSame([], $log->records);
        self::assertSame([['increment', 'fuzzphony.search_log.failed', 1.0, ['index' => 'products']]], $metrics->calls);
    }

    public function testWithTheDefaultNullLogTheRedactorIsNeverCalled(): void
    {
        $redactor = $this->createMock(QueryRedactor::class);
        $redactor->expects(self::never())->method('redact');
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate(redactor: $redactor));
        self::assertSame(1, $builder->query('mouse')->get()->total);

        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products(), gate: new SearchGate(log: new NullSearchLog(), redactor: $redactor));
        self::assertSame(1, $builder->query('mouse')->get()->total);
    }

    public function testAnEngineErrorIsNotRecorded(): void
    {
        $log = new RecordingSearchLog();
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willThrowException(InvalidQuery::missingTenant('products'));
        $builder = new SearchBuilder($engine, Indexes::products(tenant: true), gate: new SearchGate(log: $log));

        try {
            $builder->query('mouse')->get();
            self::fail('InvalidQuery expected');
        } catch (InvalidQuery) {
        }

        self::assertSame([], $log->records);
    }

    public function testASearchWithoutAGateLogsNothingAndNothingChanges(): void
    {
        // the same call through a builder without a gate: only the engine is involved
        $builder = new SearchBuilder($this->engineReturning(1, 1.0, false), Indexes::products());

        self::assertSame(1, $builder->query('mouse')->get()->total);
    }

    private function engineReturning(int $total, float $tookMs, bool $usedFuzzy): Engine
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturn(new SearchResult([], $total, false, $tookMs, $usedFuzzy, [], 20, 0));

        return $engine;
    }
}
