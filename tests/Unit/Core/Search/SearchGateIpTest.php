<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The client IP of an actor reaches the search log only as the mode allows: masked, as given, or not at all. */
final class SearchGateIpTest extends TestCase
{
    private function builder(RecordingSearchLog $log, ?IpMode $mode, bool $tenant = false): SearchBuilder
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturn(new SearchResult([], 1, false, 1.0, false, [], 20, 0));
        $gate = $mode === null ? new SearchGate(log: $log) : new SearchGate(log: $log, ipMode: $mode);

        return new SearchBuilder($engine, Indexes::products(tenant: $tenant), gate: $gate);
    }

    public function testTheDefaultStoresNoIpEvenWhenTheActorCarriesOne(): void
    {
        $log = new RecordingSearchLog();

        $this->builder($log, null)->query('mouse')->asActor(new Actor(user: 'ada', ip: '203.0.113.45'))->get();

        self::assertCount(1, $log->records);
        self::assertNull($log->records[0]->ip);
        self::assertSame('ada', $log->records[0]->user);
    }

    public function testOffStoresNoIpEvenWhenTheActorCarriesOne(): void
    {
        $log = new RecordingSearchLog();

        $this->builder($log, IpMode::Off)->query('mouse')->asActor(new Actor(user: 'ada', ip: '203.0.113.45'))->get();

        self::assertNull($log->records[0]->ip);
    }

    public function testMaskedStoresTheMaskedAddress(): void
    {
        $log = new RecordingSearchLog();
        $builder = $this->builder($log, IpMode::Masked);

        $builder->query('mouse')->asActor(new Actor(user: 'ada', ip: '203.0.113.45'))->get();
        $builder->query('mouse')->asActor(new Actor(ip: '2001:db8:abcd:12::1'))->get();
        $builder->query('mouse')->asActor(new Actor(ip: '::ffff:203.0.113.45'))->get();

        self::assertSame(['203.0.113.0', '2001:db8:abcd::', '203.0.113.0'], array_map(static fn(SearchRecord $r): ?string => $r->ip, $log->records));
        self::assertSame(['ada', null, null], array_map(static fn(SearchRecord $r): ?string => $r->user, $log->records), 'an anonymous visitor with an address stays anonymous');
    }

    public function testFullStoresTheAddressAsGiven(): void
    {
        $log = new RecordingSearchLog();

        $this->builder($log, IpMode::Full)->query('mouse')->asActor(new Actor(ip: '203.0.113.45'))->get();

        self::assertSame('203.0.113.45', $log->records[0]->ip);
    }

    public function testAnInvalidAddressIsNotStoredInAnyMode(): void
    {
        foreach ([IpMode::Masked, IpMode::Full] as $mode) {
            $log = new RecordingSearchLog();

            $this->builder($log, $mode)->query('mouse')->asActor(new Actor(ip: "1.2.3.4'; DROP TABLE x"))->get();

            self::assertNull($log->records[0]->ip, $mode->name);
        }
    }

    public function testNoActorAndNoAddressStoreNull(): void
    {
        $log = new RecordingSearchLog();
        $builder = $this->builder($log, IpMode::Full);

        $builder->query('mouse')->get();
        $builder->query('mouse')->asActor(new Actor(user: 'ada'))->get();

        self::assertSame([null, null], array_map(static fn(SearchRecord $r): ?string => $r->ip, $log->records));
    }

    public function testARefusedSearchRecordsTheAddressToo(): void
    {
        $log = new RecordingSearchLog();
        $builder = $this->builder($log, IpMode::Masked, tenant: true);

        try {
            $builder->query('x')->asActor(new Actor(user: 'ada', ip: '203.0.113.45'))->get();
            self::fail('ActorRequired expected');
        } catch (ActorRequired) {
        }

        self::assertSame([RecordStatus::Denied], array_map(static fn(SearchRecord $r): RecordStatus => $r->status, $log->records));
        self::assertSame('203.0.113.0', $log->records[0]->ip);
    }
}
