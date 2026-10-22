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
        yield 'IPv4-compatible is the IPv4 address' => ['::203.0.113.45', '203.0.113.45'];
        yield 'IPv4-compatible, hex form' => ['::cb00:712d', '203.0.113.45'];
        yield 'NAT64 is the IPv4 address' => ['64:ff9b::203.0.113.45', '203.0.113.45'];
        yield 'NAT64, hex form' => ['64:ff9b::cb00:712d', '203.0.113.45'];
        yield 'NAT64, another IPv4 address, another key' => ['64:ff9b::5.6.7.8', '5.6.7.8'];
        yield 'NAT64, long spelling' => ['0064:ff9b:0000:0000:0000:0000:cb00:712d', '203.0.113.45'];
        yield '6to4 is the IPv4 address it embeds' => ['2002:cb00:712d::1', '203.0.113.45'];
        yield '6to4, another subnet of the same /48' => ['2002:cb00:712d:ffff:1:2:3:4', '203.0.113.45'];
        yield '6to4, another IPv4 address' => ['2002:0506:0708::1', '5.6.7.8'];
        yield 'a NAT64 look-alike outside the /96 stays a /64' => ['64:ff9b:1::1.2.3.4', '64:ff9b:1::/64'];
        yield 'an address next to 2002::/16 stays a /64' => ['2003:cb00:712d::1', '2003:cb00:712d::/64'];
        yield 'loopback IPv6 is the IPv4-compatible 0.0.0.1' => ['::1', '0.0.0.1'];
        yield 'the unspecified address' => ['::', '0.0.0.0'];
        yield 'surrounding whitespace is dropped' => [" 203.0.113.45\n", '203.0.113.45'];
        yield 'brackets around IPv6' => ['[2001:db8:abcd:12::1]', '2001:db8:abcd:12::/64'];
        yield 'brackets and a port around IPv6' => ['[2001:db8:abcd:12::1]:443', '2001:db8:abcd:12::/64'];
        yield 'a port after IPv4 is dropped' => ['203.0.113.45:8080', '203.0.113.45'];
        yield 'a zone id is dropped' => ['fe80::1%eth0', 'fe80::/64'];
        yield 'a zone id in brackets' => ['[fe80::1%eth0]:80', 'fe80::/64'];
        yield 'no address' => [null, null];
        yield 'empty text' => ['', null];
        yield 'not an address' => ['not-an-ip', null];
        yield 'a header-like value' => ['6.6.6.6, 7.7.7.7', null];
        yield 'whitespace only' => ["  \t", null];
        yield 'a port without an address' => [':80', null];
        yield 'an IPv6 address with a port and no brackets is not guessed' => ['2001:db8::1:443x', null];
        yield 'a bad port' => ['203.0.113.45:99999999', null];
        yield 'brackets around IPv4 with garbage' => ['[1.2.3.4]x', null];
        yield 'a CIDR is not accepted' => ['203.0.113.0/24', null];
    }

    #[DataProvider('keys')]
    public function testTheBucketOfAnAddress(?string $ip, ?string $key): void
    {
        self::assertSame($key, ClientIpKey::of($ip));
    }

    public function testAnIpv4AddressAndAnIpv6OneNeverShareAKey(): void
    {
        self::assertNotSame(ClientIpKey::of('0.0.0.1'), ClientIpKey::of('::2:0:0:1'));
        self::assertStringEndsWith('/64', (string) ClientIpKey::of('2001:db8::1'));
        self::assertStringNotContainsString('/', (string) ClientIpKey::of('203.0.113.45'));
    }

    public function testTheSameIpv4AddressInEveryEmbeddingIsOneKeyAndOtherAddressesAreNot(): void
    {
        $same = ['203.0.113.45', '::ffff:203.0.113.45', '::203.0.113.45', '64:ff9b::203.0.113.45', '2002:cb00:712d::', '2002:cb00:712d:1:2:3:4:5', '[::ffff:203.0.113.45]:80', '203.0.113.45:80'];
        self::assertSame(['203.0.113.45'], array_values(array_unique(array_map(ClientIpKey::of(...), $same))));

        $other = ['203.0.113.46', '::ffff:203.0.113.46', '::203.0.113.46', '64:ff9b::203.0.113.46', '2002:cb00:712e::'];
        self::assertSame(['203.0.113.46', '203.0.113.46', '203.0.113.46', '203.0.113.46', '203.0.113.46'], array_map(ClientIpKey::of(...), $other));
        self::assertNotSame(ClientIpKey::of('64:ff9b::1.2.3.4'), ClientIpKey::of('64:ff9b::5.6.7.8'), 'behind a NAT64 gateway two IPv4 visitors are two buckets');
        self::assertNotSame(ClientIpKey::of('2002:cb00:712d::'), ClientIpKey::of('2002:cb00:712e::'));
    }

    public function testOnlyAValueThatHoldsSomethingButIsNoAddressIsUnparseable(): void
    {
        self::assertFalse(ClientIpKey::isUnparseable(null));
        self::assertFalse(ClientIpKey::isUnparseable(''));
        self::assertFalse(ClientIpKey::isUnparseable("  \n"));
        self::assertFalse(ClientIpKey::isUnparseable('203.0.113.45'));
        self::assertFalse(ClientIpKey::isUnparseable(' [2001:db8::1]:443'));
        self::assertTrue(ClientIpKey::isUnparseable('unknown'));
        self::assertTrue(ClientIpKey::isUnparseable('6.6.6.6, 7.7.7.7'));
    }
}
