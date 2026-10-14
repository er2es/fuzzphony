<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** The defaults and the public surface of the gate that the guard tests (which always pass $charge) do not reach. */
final class SearchGateMutationsTest extends TestCase
{
    public function testSearchChargesByDefault(): void
    {
        $guard = new ChargeNotingGuard();
        $gate = new SearchGate(guards: [$guard]);
        $query = (new SearchQuery('mouse'))->withActor(new Actor(user: 'ada'));

        $gate->search($this->engine(), Indexes::products(), $query);

        self::assertSame([true], $guard->charges);
    }

    public function testAccessIsPublicAndChargesByDefault(): void
    {
        $guard = new ChargeNotingGuard();
        $gate = new SearchGate(guards: [$guard]);

        $access = $gate->access(Indexes::products(), new Actor(user: 'ada'));

        self::assertTrue($access->isAll());
        self::assertSame([true], $guard->charges);
    }

    public function testAccessCountedChargesByDefault(): void
    {
        $guard = new ChargeNotingGuard();
        $gate = new SearchGate(guards: [$guard]);

        $gate->accessCounted(Indexes::products(), new Actor(user: 'ada'));

        self::assertSame([true], $guard->charges);
    }

    public function testTheRefusalLogLineCarriesTheMessageAndTheIndex(): void
    {
        $logger = new RecordingLogger();
        $gate = new SearchGate(logger: $logger, guards: [new RefusingGuard(new RateLimited(5))]);

        try {
            $gate->access(Indexes::products(), new Actor(user: 'ada'));
        } catch (RateLimited) {
        }
        try {
            $gate->accessCounted(Indexes::products(), new Actor(user: 'ada'));
        } catch (RateLimited) {
        }

        self::assertSame(
            [['warning', ['message' => 'Too many searches: try again in 5 seconds.', 'index' => 'products']]],
            $logger->calls,
            'only the counted access logs the refusal, with the exception message and the index name as context',
        );
    }

    private function engine(): Engine
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturn(SearchResult::empty(20, 0));

        return $engine;
    }
}

/** @internal Remembers whether each check was asked to charge. */
final class ChargeNotingGuard implements SearchGuard
{
    /** @var list<bool> */
    public array $charges = [];

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        $this->charges[] = $charge;

        return Access::all();
    }
}

/** @internal Always refuses with the exception it was given. */
final readonly class RefusingGuard implements SearchGuard
{
    public function __construct(private \Throwable $refusal) {}

    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        throw $this->refusal;
    }
}
