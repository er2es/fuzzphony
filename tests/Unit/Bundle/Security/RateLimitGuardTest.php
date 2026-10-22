<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\RecordingLimiterStorage;
use Fuzzphony\Tests\Unit\Core\Observability\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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

    public function testPerIpHandsTheLimiterAnHmacAndNeverTheAddress(): void
    {
        $storage = $this->recordingStorage();
        $guard = new RateLimitGuard($this->factoryOver($storage), per: 'ip', secret: 'app-secret');

        $guard->check($this->index(), new Actor(ip: '203.0.113.45'));
        $guard->check($this->index(), new Actor(ip: '2001:db8:abcd:12::1'));

        self::assertCount(2, $storage->saved);
        foreach ([...$storage->saved, ...$storage->states] as $saved) {
            self::assertStringNotContainsString('203.0.113', $saved);
            self::assertStringNotContainsString('2001:db8', $saved);
        }
        self::assertStringContainsString('search-' . hash_hmac('sha256', '203.0.113.45', 'app-secret'), $storage->saved[0]);
        self::assertStringNotContainsString(hash('sha256', '203.0.113.45'), $storage->saved[0], 'a plain hash of the address would be recovered by enumeration');
        self::assertStringContainsString('search-' . hash_hmac('sha256', '2001:db8:abcd:12::/64', 'app-secret'), $storage->saved[1]);
    }

    public function testTheHmacKeyIsStableForOneSecretAndDiffersBetweenSecrets(): void
    {
        $ids = [];
        foreach (['one', 'one', 'two'] as $secret) {
            $storage = $this->recordingStorage();
            (new RateLimitGuard($this->factoryOver($storage), per: 'ip', secret: $secret))->check($this->index(), new Actor(ip: '64:ff9b::203.0.113.45'));
            $ids[] = $storage->saved[0];
        }

        self::assertSame($ids[0], $ids[1], 'the same secret on another server or another request: the same bucket');
        self::assertNotSame($ids[0], $ids[2], 'a rotated secret starts every bucket afresh');
        self::assertStringContainsString(hash_hmac('sha256', '203.0.113.45', 'one'), $ids[0], 'the key is the IPv4 address the NAT64 form embeds');
    }

    public function testWithoutASecretTheDocumentedDefaultIsUsed(): void
    {
        foreach ([[], ['secret' => '']] as $arguments) {
            $storage = $this->recordingStorage();
            (new RateLimitGuard($this->factoryOver($storage), 'ip', ...$arguments))->check($this->index(), new Actor(ip: '203.0.113.45'));

            self::assertStringContainsString(hash_hmac('sha256', '203.0.113.45', RateLimitGuard::DEFAULT_SECRET), $storage->saved[0]);
        }
    }

    public function testTenantAndUserKeysAreNotHashed(): void
    {
        $storage = $this->recordingStorage();
        (new RateLimitGuard($this->factoryOver($storage), 'tenant', 'app-secret'))->check($this->index(), new Actor(tenant: 7));

        self::assertStringContainsString('search-7', $storage->saved[0]);
    }

    public function testAnAddressThatCannotBeKeyedIsNotLimitedAndReportedOnceWithoutItsValue(): void
    {
        RateLimitGuard::forgetReports();
        $metrics = new RecordingMetricsCollector();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = json_encode([$level, (string) $message, $context], \JSON_THROW_ON_ERROR);
            }
        };
        $guard = new RateLimitGuard($this->factory(limit: 1), 'ip', 'app-secret', $metrics, $logger);

        foreach (['secret-garbage', '6.6.6.6, 7.7.7.7', 'unknown'] as $junk) {
            for ($i = 0; $i < 3; ++$i) {
                self::assertTrue($guard->check($this->index(), new Actor(ip: $junk))->isAll(), 'fail open');
            }
        }

        self::assertSame([['increment', 'fuzzphony.search.rate_limit_unkeyed', 1.0, []]], $metrics->calls, 'one counter, once per process, no label');
        self::assertCount(1, $logger->lines);
        self::assertStringNotContainsString('garbage', $logger->lines[0]);
        self::assertStringNotContainsString('6.6.6.6', $logger->lines[0]);
        RateLimitGuard::forgetReports();
    }

    public function testNoAddressAndNormalisableAddressesAreNotReported(): void
    {
        RateLimitGuard::forgetReports();
        $metrics = new RecordingMetricsCollector();
        $guard = new RateLimitGuard($this->factory(limit: 100), 'ip', 'app-secret', $metrics);

        foreach ([new Actor(), new Actor(ip: ''), new Actor(ip: '   '), new Actor(ip: '203.0.113.45:80'), new Actor(ip: '[2001:db8::1]:80'), new Actor(ip: 'fe80::1%eth0')] as $actor) {
            $guard->check($this->index(), $actor);
        }

        self::assertSame([], $metrics->calls);
    }

    public function testNormalisedSpellingsShareTheBucketOfTheAddress(): void
    {
        $guard = new RateLimitGuard($this->factory(limit: 1), per: 'ip');

        $guard->check($this->index(), new Actor(ip: '203.0.113.45'));

        $this->expectException(RateLimited::class);
        $guard->check($this->index(), new Actor(ip: ' 203.0.113.45:8080'));
    }

    private function recordingStorage(): RecordingLimiterStorage
    {
        return new RecordingLimiterStorage();
    }

    private function factoryOver(RecordingLimiterStorage $storage): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'search', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 minute'], $storage);
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
