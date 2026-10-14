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
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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
            self::assertLessThanOrEqual(60, $e->retryAfter);
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

    public function testAnUnknownPerIsRejected(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('fuzzphony.rate_limit.per must be "tenant" or "user", got "ip"');

        new RateLimitGuard($this->factory(limit: 1), per: 'ip');
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
