<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

final class SearchGateGuardsTest extends TestCase
{
    public function testAnActorSearchRunsEveryGuardAndIntersectsTheFields(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only(['name', 'brand'])), new FixedGuard(Access::only(['name', 'description']))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([['name']], $engine->allowedFields);
    }

    public function testTheGuardResultIsIntersectedWithTheFieldsTheQueryAlreadyAllows(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only(['name', 'brand']))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->restrictedToFields('brand', 'description')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([['brand']], $engine->allowedFields);
    }

    public function testASearchWithoutAnActorSkipsTheGuards(): void
    {
        $guard = new CountingGuard();
        $engine = new GuardsRecorder();

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: new SearchGate(guards: [$guard])))->query('mouse')->get();

        self::assertSame(0, $guard->calls);
        self::assertSame([null], $engine->allowedFields);
    }

    public function testAnActorSearchWithoutGuardsIsUnrestricted(): void
    {
        $engine = new GuardsRecorder();

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: new SearchGate()))->query('mouse')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([null], $engine->allowedFields);
    }

    public function testAllFieldsAllowedKeepsTheUnrestrictedQuery(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only(['description', 'brand', 'name', 'extra']))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([null], $engine->allowedFields);
    }

    public function testAllFieldsAllowedKeepsTheFieldsTheQueryAlreadyHas(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only(['description', 'brand', 'name']))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->restrictedToFields('name')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([['name']], $engine->allowedFields);
    }

    public function testADeniedSearchNeverReachesTheEngineIsCountedLoggedAndRecorded(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new RecordingSearchLog();
        $engine = new GuardsRecorder();
        $gate = new SearchGate($metrics, log: $log, guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]);
        $builder = (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'));

        try {
            $builder->get();
            self::fail('AccessDenied expected');
        } catch (AccessDenied $e) {
            self::assertSame('Searching index "products" is not granted to this actor.', $e->getMessage());
        }

        self::assertSame([], $engine->allowedFields);
        self::assertSame([['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'authorization']]], $metrics->calls);
        self::assertCount(1, $log->records);
        self::assertSame([RecordStatus::Denied, 'mouse', 'ada', 0], [$log->records[0]->status, $log->records[0]->query, $log->records[0]->user, $log->records[0]->hits]);
    }

    public function testADeniedUnloggedSearchIsStillRecordedWithTheTextTheVisitorTyped(): void
    {
        $log = new RecordingSearchLog();
        $gate = new SearchGate(log: $log, guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]);
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: $gate))->query('wireless mou')->asYouType()->unlogged()->asActor(new Actor(user: 'ada'));

        try {
            $builder->get();
            self::fail('AccessDenied expected');
        } catch (AccessDenied) {
        }

        self::assertCount(1, $log->records);
        self::assertSame([RecordStatus::Denied, 'wireless mou'], [$log->records[0]->status, $log->records[0]->query]);
    }

    public function testARateLimitedSearchIsCountedAndRecordedAndKeepsItsRetryAfter(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new RecordingSearchLog();
        $engine = new GuardsRecorder();
        $gate = new SearchGate($metrics, log: $log, guards: [new ThrowingGuard(new RateLimited(30))]);
        $builder = (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'));

        try {
            $builder->get();
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertSame(30, $e->retryAfter);
        }

        self::assertSame([], $engine->allowedFields);
        self::assertSame([['increment', 'fuzzphony.search.rate_limited', 1.0, ['index' => 'products']]], $metrics->calls);
        self::assertSame([RecordStatus::RateLimited], array_map(static fn($r): RecordStatus => $r->status, $log->records));
    }

    public function testARefusalIsLoggedToThePsrLogger(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('refused a search'), self::anything());
        $gate = new SearchGate(logger: $logger, guards: [new ThrowingGuard(new RateLimited(5))]);

        try {
            (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: $gate))->query('x')->asActor(new Actor(user: 'ada'))->get();
        } catch (RateLimited) {
        }
    }

    public function testExplainRunsTheGuardsToo(): void
    {
        $engine = new GuardsRecorder();
        $builder = (new SearchBuilder($this->fake($engine), Indexes::products(), gate: new SearchGate(guards: [new ThrowingGuard(AccessDenied::forIndex('products'))])))->query('mouse')->asActor(new Actor(user: 'ada'));

        $this->expectException(AccessDenied::class);
        try {
            $builder->explain();
        } finally {
            self::assertSame(0, $engine->explains);
        }
    }

    public function testExplainSearchesOnlyTheAllowedFields(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only(['name']))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'))->explain();

        self::assertSame([['name']], $engine->explainedFields);
    }

    public function testAFederatedSearchChargesOnce(): void
    {
        $guard = new CountingGuard();
        $registry = new IndexRegistry([Indexes::products(), Indexes::products()->withName('articles'), Indexes::products()->withName('pages')]);
        $fuzzphony = new Fuzzphony($this->fake(new GuardsRecorder()), $registry, new SearchGate(guards: [$guard]));
        $asAda = static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(user: 'ada'));

        $fuzzphony->federated()->index('products', 1.0, $asAda)->index('articles', 1.0, $asAda)->index('pages', 1.0, $asAda)->query('mouse')->get();

        self::assertSame(3, $guard->calls);
        self::assertSame(1, $guard->charged);
    }

    public function testAFederatedSearchChargesTheFirstIndexSearchedAsAnActor(): void
    {
        $guard = new CountingGuard();
        $fuzzphony = new Fuzzphony($this->fake(new GuardsRecorder()), $this->threeIndexes(), new SearchGate(guards: [$guard]));
        $asAda = static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(user: 'ada'));

        $fuzzphony->federated()->index('products')->index('articles', 1.0, $asAda)->index('pages', 1.0, $asAda)->query('mouse')->get();

        self::assertSame([2, 1], [$guard->calls, $guard->charged]);
    }

    public function testAFederatedSearchWithoutAnActorNeverCallsTheGuards(): void
    {
        $guard = new CountingGuard();
        $fuzzphony = new Fuzzphony($this->fake(new GuardsRecorder()), $this->threeIndexes(), new SearchGate(guards: [$guard]));

        $fuzzphony->federated()->index('products')->index('articles')->index('pages')->query('mouse')->get();

        self::assertSame([0, 0], [$guard->calls, $guard->charged]);
    }

    public function testARefusedFederatedSearchRethrowsAndIsCountedOnce(): void
    {
        $metrics = new RecordingMetricsCollector();
        $fuzzphony = new Fuzzphony($this->fake(new GuardsRecorder()), $this->threeIndexes(), new SearchGate($metrics, guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]));
        $asAda = static fn(SearchBuilder $b): SearchBuilder => $b->asActor(new Actor(user: 'ada'));

        try {
            $fuzzphony->federated()->index('products', 1.0, $asAda)->index('articles', 1.0, $asAda)->query('mouse')->get();
            self::fail('AccessDenied expected');
        } catch (AccessDenied) {
        }

        self::assertSame([['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'authorization']]], $metrics->calls);
    }

    /** @return iterable<string, array{Access, list<string>}> */
    public static function oddAccesses(): iterable
    {
        yield 'no field at all' => [Access::only([]), []];
        yield 'only an unknown name' => [Access::only(['nme']), []];
        yield 'a known and an unknown name' => [Access::only(['name', 'nme']), ['name']];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('oddAccesses')]
    public function testTheGateKeepsOnlyTheKnownFieldsAndAnEmptyListFailsClosed(Access $access, array $expected): void
    {
        $engine = new GuardsRecorder();

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: new SearchGate(guards: [new FixedGuard($access)])))->query('mouse')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([$expected], $engine->allowedFields);
    }

    public function testAnEmptyAccessIntersectedWithTheQueryFieldsStaysEmpty(): void
    {
        $engine = new GuardsRecorder();
        $gate = new SearchGate(guards: [new FixedGuard(Access::only([]))]);

        (new SearchBuilder($this->fake($engine), Indexes::products(), gate: $gate))->query('mouse')->restrictedToFields('name')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([[]], $engine->allowedFields);
    }

    public function testAGuardThatFailsWithAnotherExceptionPropagatesAndIsNeitherCountedNorRecorded(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new RecordingSearchLog();
        $gate = new SearchGate($metrics, log: $log, guards: [new ThrowingGuard(new \RuntimeException('limiter down'))]);
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'));

        try {
            $builder->get();
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertSame('limiter down', $e->getMessage());
        }

        self::assertSame([], $metrics->calls);
        self::assertSame([], $log->records);
    }

    public function testTheRefusalIsRethrownAsTheSameInstance(): void
    {
        foreach ([AccessDenied::forIndex('products'), new RateLimited(30)] as $refusal) {
            $gate = new SearchGate(guards: [new ThrowingGuard($refusal)]);
            $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'));

            try {
                $builder->get();
                self::fail($refusal::class . ' expected');
            } catch (AccessDenied|RateLimited $caught) {
                self::assertSame($refusal, $caught);
            }
        }
    }

    public function testADenyingGuardBeforeACountingGuardLeavesItUncalled(): void
    {
        $counting = new CountingGuard();
        $gate = new SearchGate(guards: [new ThrowingGuard(AccessDenied::forIndex('products')), $counting]);
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: $gate))->query('mouse')->asActor(new Actor(user: 'ada'));

        try {
            $builder->get();
            self::fail('AccessDenied expected');
        } catch (AccessDenied) {
        }

        self::assertSame(0, $counting->calls);
    }

    public function testARefusedSuggestIsCountedAndWrittenToTheSearchLog(): void
    {
        $metrics = new RecordingMetricsCollector();
        $log = new RecordingSearchLog();
        $registry = new IndexRegistry([Indexes::products()]);
        $denied = new Fuzzphony($this->suggestingEngine(), $registry, new SearchGate($metrics, log: $log, guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]));
        $limited = new Fuzzphony($this->suggestingEngine(), $registry, new SearchGate($metrics, log: $log, guards: [new ThrowingGuard(new RateLimited(9))]));

        foreach ([$denied, $limited] as $fuzzphony) {
            try {
                $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada'));
                self::fail('a refusal expected');
            } catch (AccessDenied|RateLimited) {
            }
        }

        self::assertSame([
            ['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'authorization']],
            ['increment', 'fuzzphony.search.rate_limited', 1.0, ['index' => 'products']],
        ], $metrics->calls);
        self::assertSame(
            [[RecordStatus::Denied, 'wir', 'ada', 0], [RecordStatus::RateLimited, 'wir', 'ada', 0]],
            array_map(static fn($r): array => [$r->status, $r->query, $r->user, $r->hits], $log->records),
            'a refusal is never silent: the typed text is in the log, like for a search',
        );
    }

    private function threeIndexes(): IndexRegistry
    {
        return new IndexRegistry([Indexes::products(), Indexes::products()->withName('articles'), Indexes::products()->withName('pages')]);
    }

    public function testAnUnchargedSearchRunsTheGuardsWithoutCharging(): void
    {
        $guard = new CountingGuard();
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: new SearchGate(guards: [$guard])))->query('mouse')->asActor(new Actor(user: 'ada'));

        $builder->getUncharged();

        self::assertSame([1, 0], [$guard->calls, $guard->charged]);
    }

    public function testExplainConsumesAToken(): void
    {
        $guard = new CountingGuard();
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: new SearchGate(guards: [$guard])))->query('mouse')->asActor(new Actor(user: 'ada'));

        $builder->explain();

        self::assertSame([1, 1], [$guard->calls, $guard->charged]);
    }

    public function testASearchWithoutAGateIgnoresGetUncharged(): void
    {
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products()))->query('mouse');

        self::assertSame(0, $builder->getUncharged()->total);
    }

    public function testAnUnchargedSearchIsRecordedWithTheTextTheVisitorTyped(): void
    {
        $log = new RecordingSearchLog();
        $builder = (new SearchBuilder($this->fake(new GuardsRecorder()), Indexes::products(), gate: new SearchGate(log: $log)))->query('wireless mou')->asYouType();

        $builder->getUncharged();

        self::assertSame(['wireless mou'], array_map(static fn($r): string => $r->query, $log->records));
    }

    public function testSuggestAsARestrictedActorIsEmpty(): void
    {
        $engine = $this->suggestingEngine();
        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products()]), new SearchGate(guards: [new FixedGuard(Access::only(['name']))]));

        self::assertSame([], $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada')));
    }

    public function testSuggestAsAnUnrestrictedActorGivesTheEnginesWords(): void
    {
        $fuzzphony = new Fuzzphony($this->suggestingEngine(), new IndexRegistry([Indexes::products()]), new SearchGate(guards: [new FixedGuard(Access::all())]));

        self::assertSame(['wireless'], $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada')));
    }

    public function testSuggestWithoutAnActorSkipsTheGuards(): void
    {
        $guard = new CountingGuard();
        $fuzzphony = new Fuzzphony($this->suggestingEngine(), new IndexRegistry([Indexes::products()]), new SearchGate(guards: [$guard]));

        self::assertSame(['wireless'], $fuzzphony->suggest('products', 'wir'));
        self::assertSame(0, $guard->calls);
    }

    public function testSuggestChargesTheActorUnlessToldNotTo(): void
    {
        $guard = new CountingGuard();
        $fuzzphony = new Fuzzphony($this->suggestingEngine(), new IndexRegistry([Indexes::products()]), new SearchGate(guards: [$guard]));

        $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada'));
        self::assertSame([1, 1], [$guard->calls, $guard->charged], 'suggest() charges like a search');

        $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada'), charge: false);
        self::assertSame([2, 1], [$guard->calls, $guard->charged], 'charge: false still runs the guards but spends nothing');
    }

    public function testAnUnchargedAccessCountedStillRunsTheGuardsAndCountsARefusal(): void
    {
        $metrics = new RecordingMetricsCollector();
        $gate = new SearchGate($metrics, guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]);

        try {
            $gate->accessCounted(Indexes::products(), new Actor(user: 'ada'), charge: false);
            self::fail('AccessDenied expected');
        } catch (AccessDenied) {
        }

        self::assertSame([['increment', 'fuzzphony.search.denied', 1.0, ['index' => 'products', 'reason' => 'authorization']]], $metrics->calls);
    }

    public function testSuggestWithoutAGateIgnoresTheActor(): void
    {
        $fuzzphony = new Fuzzphony($this->suggestingEngine(), new IndexRegistry([Indexes::products()]));

        self::assertSame(['wireless'], $fuzzphony->suggest('products', 'wir', 8, new Actor(user: 'ada')));
    }

    public function testSuggestPropagatesADenialAndARateLimit(): void
    {
        $registry = new IndexRegistry([Indexes::products()]);
        $denied = new Fuzzphony($this->suggestingEngine(), $registry, new SearchGate(guards: [new ThrowingGuard(AccessDenied::forIndex('products'))]));
        $limited = new Fuzzphony($this->suggestingEngine(), $registry, new SearchGate(guards: [new ThrowingGuard(new RateLimited(9))]));

        try {
            $denied->suggest('products', 'wir', 8, new Actor(user: 'ada'));
            self::fail('AccessDenied expected');
        } catch (AccessDenied) {
        }
        try {
            $limited->suggest('products', 'wir', 8, new Actor(user: 'ada'));
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertSame(9, $e->retryAfter);
        }
    }

    private function fake(GuardsRecorder $recorder): Engine
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(static function (IndexDefinition $index, SearchQuery $query) use ($recorder): SearchResult {
            $recorder->allowedFields[] = $query->allowedFields;

            return SearchResult::empty(20, 0);
        });
        $engine->method('explain')->willReturnCallback(static function (IndexDefinition $index, SearchQuery $query) use ($recorder): Explanation {
            ++$recorder->explains;
            $recorder->explainedFields[] = $query->allowedFields;

            return new Explanation('mouse', [], [], SearchResult::empty(20, 0));
        });

        return $engine;
    }

    private function suggestingEngine(): Engine
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        $engine->method('suggestWords')->willReturn(['wireless']);
        assert($engine instanceof Engine);

        return $engine;
    }
}

/** @internal Remembers the allowed fields of every search and explain the fake engine receives. */
final class GuardsRecorder
{
    /** @var list<list<string>|null> */
    public array $allowedFields = [];

    /** @var list<list<string>|null> */
    public array $explainedFields = [];

    public int $explains = 0;
}

/** @internal Returns the Access it was given. */
final readonly class FixedGuard implements SearchGuard
{
    public function __construct(private Access $access) {}

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        return $this->access;
    }
}

/** @internal Throws the exception it was given. */
final readonly class ThrowingGuard implements SearchGuard
{
    public function __construct(private \Throwable $e) {}

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        throw $this->e;
    }
}

/** @internal Counts its calls and the ones that charge. */
final class CountingGuard implements SearchGuard
{
    public int $calls = 0;

    public int $charged = 0;

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        ++$this->calls;
        if ($charge) {
            ++$this->charged;
        }

        return Access::all();
    }
}
