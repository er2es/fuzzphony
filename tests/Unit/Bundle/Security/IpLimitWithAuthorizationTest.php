<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Tests\Fixtures\FakeAuthorizationChecker;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/** The guards the bundle builds in the order it builds them (authorization first), through the search gate. */
final class IpLimitWithAuthorizationTest extends TestCase
{
    public function testPerIpAloneLimitsAnonymousVisitorsAndNeverDeniesThem(): void
    {
        $gate = new SearchGate(guards: [$this->limit('ip', 2)]);
        $visitor = new Actor(ip: '203.0.113.45');

        self::assertTrue($gate->access(Indexes::products(), $visitor)->isAll());
        self::assertTrue($gate->access(Indexes::products(), $visitor)->isAll());
        try {
            $gate->access(Indexes::products(), $visitor);
            self::fail('RateLimited expected');
        } catch (RateLimited $e) {
            self::assertSame(1, preg_match('/^Too many searches: try again in \d+ seconds\.$/', $e->getMessage()));
        }
        self::assertTrue($gate->access(Indexes::products(), new Actor(ip: '203.0.113.46'))->isAll(), 'another visitor');
    }

    public function testPerIpWithAuthorizationLimitsASignedInUserByItsAddressToo(): void
    {
        $gate = $this->gateWithAuthorization(signedIn: true, granted: true, limit: 1);
        $ada = new Actor(tenant: 1, user: 'ada', ip: '203.0.113.45');

        $gate->access(Indexes::products(), $ada);

        $this->expectException(RateLimited::class);
        $gate->access(Indexes::products(), $ada);
    }

    public function testADeniedSearchSpendsNoTokenOfTheAddress(): void
    {
        $storage = new InMemoryStorage();
        $denied = $this->gateWithAuthorization(signedIn: true, granted: false, limit: 1, storage: $storage);
        $visitor = new Actor(ip: '203.0.113.45');

        for ($i = 0; $i < 5; ++$i) {
            try {
                $denied->access(Indexes::products(), $visitor);
                self::fail('AccessDenied expected');
            } catch (AccessDenied) {
            }
        }

        // the same limiter state, now for a granted viewer: the one token is still there
        $granted = $this->gateWithAuthorization(signedIn: true, granted: true, limit: 1, storage: $storage);
        self::assertTrue($granted->access(Indexes::products(), $visitor)->isAll());
    }

    public function testWithAuthorizationAnAnonymousVisitorWithoutATokenIsRefusedAsToday(): void
    {
        $gate = $this->gateWithAuthorization(signedIn: false, granted: true, limit: 5);

        $this->expectException(AccessDenied::class);

        $gate->access(Indexes::products(), new Actor(ip: '203.0.113.45'));
    }

    public function testPerTenantAndPerUserDoNotLookAtTheAddress(): void
    {
        foreach (['tenant' => new Actor(tenant: 1, ip: '203.0.113.45'), 'user' => new Actor(user: 'ada', ip: '203.0.113.45')] as $per => $actor) {
            $gate = new SearchGate(guards: [$this->limit($per, 1)]);
            $gate->access(Indexes::products(), $actor);
            $moved = $per === 'tenant' ? new Actor(tenant: 1, ip: '198.51.100.7') : new Actor(user: 'ada', ip: '198.51.100.7');

            try {
                $gate->access(Indexes::products(), $moved); // another address, the same tenant or user: the same bucket
                self::fail('RateLimited expected for per: ' . $per);
            } catch (RateLimited) {
            }
            self::assertTrue($gate->access(Indexes::products(), new Actor(ip: '203.0.113.45'))->isAll(), 'without the key there is no bucket, whatever the address');
        }
    }

    private function limit(string $per, int $limit, ?InMemoryStorage $storage = null): RateLimitGuard
    {
        return new RateLimitGuard(new RateLimiterFactory(['id' => 'search', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], $storage ?? new InMemoryStorage()), $per);
    }

    private function gateWithAuthorization(bool $signedIn, bool $granted, int $limit, ?InMemoryStorage $storage = null): SearchGate
    {
        $tokens = new TokenStorage();
        if ($signedIn) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main'));
        }
        $checker = new FakeAuthorizationChecker(['FUZZPHONY_SEARCH' => ['products' => $granted]]);

        return new SearchGate(guards: [new AuthorizationGuard($checker, $tokens), $this->limit('ip', $limit, $storage)]);
    }
}
