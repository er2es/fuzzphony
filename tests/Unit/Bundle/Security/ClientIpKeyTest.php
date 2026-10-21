<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\ClientIpKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIpKeyTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null}> */
    public static function keys(): iterable
    {
        yield 'IPv4 is its own bucket' => ['203.0.113.45', '203.0.113.45'];
        yield 'a neighbour IPv4 is another bucket' => ['203.0.113.46', '203.0.113.46'];
        yield 'IPv6 is its /64' => ['2001:db8:abcd:12:aaaa:bbbb:cccc:dddd', '2001:db8:abcd:12::/64'];
        yield 'the same /64, another host part' => ['2001:db8:abcd:12::1', '2001:db8:abcd:12::/64'];
        yield 'the next /64 is another bucket' => ['2001:db8:abcd:13::1', '2001:db8:abcd:13::/64'];
        yield 'spelling does not matter' => ['2001:0DB8:ABCD:0012:0:0:0:1', '2001:db8:abcd:12::/64'];
        yield 'IPv4-mapped IPv6 is the IPv4 address' => ['::ffff:203.0.113.45', '203.0.113.45'];
        yield 'IPv4-mapped, hex form' => ['::ffff:cb00:712d', '203.0.113.45'];
        yield 'loopback IPv6' => ['::1', '::/64'];
        yield 'no address' => [null, null];
        yield 'empty text' => ['', null];
        yield 'not an address' => ['not-an-ip', null];
        yield 'a header-like value' => ['6.6.6.6, 7.7.7.7', null];
        yield 'an IPv6 zone id is not accepted' => ['fe80::1%eth0', null];
        yield 'a port is not accepted' => ['203.0.113.45:8080', null];
        yield 'a CIDR is not accepted' => ['203.0.113.0/24', null];
    }

    #[DataProvider('keys')]
    public function testTheBucketOfAnAddress(?string $ip, ?string $key): void
    {
        self::assertSame($key, ClientIpKey::of($ip));
    }

    public function testAnIpv4AddressAndAnIpv6OneNeverShareAKey(): void
    {
        self::assertNotSame(ClientIpKey::of('0.0.0.1'), ClientIpKey::of('::1'));
        self::assertStringEndsWith('/64', (string) ClientIpKey::of('2001:db8::1'));
        self::assertStringNotContainsString('/', (string) ClientIpKey::of('203.0.113.45'));
    }
}
