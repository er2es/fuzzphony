<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

final class RateLimitGuardTest extends TestCase
{
    public function testTheThirdSearchOfATenantIsLimitedAndTheOthersAreNot(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 2), per: 'tenant');
        $acme = new Actor(tenant: 1, user: 'ada');
        $other = new Actor(tenant: 2, user: 'bob');

        $guard->check($this->index(), $acme);
        $guard->check($this->index(), $acme);
        $guard->check($this->index(), $other); // another tenant has its own bucket

        try {
            $guard->check($this->index(), $acme);
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
            // a one-minute window: the time left is rounded up, so a fraction of a second over 60 reads as 61
            self::assertLessThanOrEqual(61, $e->retryAfter);
        }
    }

    public function testPerUserKeysOnTheUser(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'user');

        $guard->check($this->index(), new Actor(tenant: 1, user: 'ada'));
        $guard->check($this->index(), new Actor(tenant: 1, user: 'bob')); // same tenant, own bucket

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), new Actor(tenant: 2, user: 'ada')); // same user, other tenant: the same bucket
    }

    public function testAnActorWithoutTheKeyIsNotLimited(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'tenant');
        $userOnly = new Actor(user: 'ada');

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($guard->check($this->index(), $userOnly)->isAll());
        }

        $perUser = new RateLimitGuard($this->factory(limit: 1), per: 'user');
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($perUser->check($this->index(), new Actor(tenant: 1))->isAll());
        }
    }

    public function testAnUnchargedCheckConsumesNothing(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'tenant');
        $actor = new Actor(tenant: 1);

        for ($i = 0; $i < 3; ++$i) {
            $guard->check($this->index(), $actor, charge: false);
        }
        $guard->check($this->index(), $actor); // the one token is still there

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), $actor);
    }

    public function testAccessIsAlwaysAll(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 5), per: 'tenant');

        self::assertTrue($guard->check($this->index(), new Actor(tenant: 1))->isAll());
        self::assertTrue($guard->check($this->index(), new Actor(tenant: 1), charge: false)->isAll());
    }

    public function testACompoundLimiterFactoryWorksToo(): void
    {
        // framework.rate_limiter.x: {policy: compound, limiters: [...]} registers limiter.x as a CompoundRateLimiterFactory
        $compound = new CompoundRateLimiterFactory([$this->factory(limit: 2), $this->factory(limit: 1)]);
        $guard = new RateLimitGuard($compound, per: 'tenant');
        $actor = new Actor(tenant: 1);

        $guard->check($this->index(), $actor);

        try {
            $guard->check($this->index(), $actor); // the stricter of the two limiters refuses
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
        }
        $guard->check($this->index(), new Actor(tenant: 2)); // another tenant has its own bucket
    }

    public function testPerIpKeysOnTheClientAddressAndIgnoresTenantAndUser(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 2), per: 'ip');

        $guard->check($this->index(), new Actor(ip: '203.0.113.45'));
        $guard->check($this->index(), new Actor(tenant: 1, user: 'ada', ip: '203.0.113.45')); // a signed-in user shares the bucket of the address
        $guard->check($this->index(), new Actor(ip: '203.0.113.46')); // another address, another bucket

        try {
            $guard->check($this->index(), new Actor(tenant: 9, user: 'bob', ip: '203.0.113.45'));
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
            self::assertStringNotContainsString('203.0.113', $e->getMessage(), 'the address is never in a message');
        }
    }

    public function testPerIpSharesOneBucketInsideAnIpv6Slash64AndNotAcrossIt(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'ip');

        $guard->check($this->index(), new Actor(ip: '2001:db8:abcd:12::1'));
        $guard->check($this->index(), new Actor(ip: '2001:db8:abcd:13::1')); // the next /64

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), new Actor(ip: '2001:db8:abcd:12:ffff:ffff:ffff:ffff')); // rotating inside the /64 does not help
    }

    public function testPerIpTreatsAnIpv4MappedAddressAsTheIpv4One(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'ip');

        $guard->check($this->index(), new Actor(ip: '203.0.113.45'));

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), new Actor(ip: '::ffff:203.0.113.45'));
    }

    public function testPerIpDoesNotLimitAnActorWithoutAUsableAddress(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'ip');

        foreach ([new Actor(), new Actor(tenant: 1, user: 'ada'), new Actor(ip: 'not-an-ip'), new Actor(ip: '')] as $actor) {
            for ($i = 0; $i < 4; ++$i) {
                self::assertTrue($guard->check($this->index(), $actor)->isAll());
            }
        }
    }

    public function testPerIpUnchargedCheckConsumesNothing(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'ip');
        $actor = new Actor(ip: '203.0.113.45');

        for ($i = 0; $i < 3; ++$i) {
            $guard->check($this->index(), $actor, charge: false);
        }
        $guard->check($this->index(), $actor);

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), $actor);
    }

    public function testTenantAndUserIgnoreTheAddress(): void
    {
        $perTenant = new RateLimitGuard($this->factory(limit: 1), per: 'tenant');
        $perTenant->check($this->index(), new Actor(tenant: 1, ip: '203.0.113.45'));
        $perTenant->check($this->index(), new Actor(tenant: 2, ip: '203.0.113.45')); // same address, another tenant: its own bucket

        $perUser = new RateLimitGuard($this->factory(limit: 1), per: 'user');
        $perUser->check($this->index(), new Actor(user: 'ada', ip: '203.0.113.45'));
        $perUser->check($this->index(), new Actor(user: 'bob', ip: '203.0.113.45'));
        self::assertTrue($perUser->check($this->index(), new Actor(ip: '203.0.113.45'))->isAll(), 'no user, no bucket');
        self::assertTrue($perUser->check($this->index(), new Actor(ip: '203.0.113.45'))->isAll());
    }

    public function testPerIpHandsTheLimiterAHashAndNeverTheAddress(): void
    {
        $storage = new class implements StorageInterface {
            /** @var list<string> */
            public array $saved = [];

            public function save(LimiterStateInterface $limiterState): void
            {
                $this->saved[] = $limiterState->getId() . '|' . serialize($limiterState);
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                return null;
            }

            public function delete(string $limiterStateId): void {}
        };
        $guard = new RateLimitGuard(new RateLimiterFactory(['id' => 'search', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 minute'], $storage), per: 'ip');

        $guard->check($this->index(), new Actor(ip: '203.0.113.45'));
        $guard->check($this->index(), new Actor(ip: '2001:db8:abcd:12::1'));

        self::assertCount(2, $storage->saved);
        foreach ($storage->saved as $saved) {
            self::assertStringNotContainsString('203.0.113', $saved);
            self::assertStringNotContainsString('2001:db8', $saved);
        }
        self::assertStringContainsString('search-' . hash('sha256', '203.0.113.45'), $storage->saved[0]);
    }

    public function testAnUnknownPerIsRejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('fuzzphony.rate_limit.per must be "tenant", "user" or "ip", got "account"');

        new RateLimitGuard($this->factory(limit: 1), per: 'account');
    }

    private function factory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'search', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }

    private function index(): IndexDefinition
    {
        return Indexes::products();
    }
}
