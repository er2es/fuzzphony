<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\IpMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the search log may keep of a client IP: nothing, the masked address, or the valid address as given. */
final class IpModeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function maskedAddresses(): iterable
    {
        yield 'IPv4' => ['203.0.113.45', '203.0.113.0'];
        yield 'IPv4 last octet 255' => ['203.0.113.255', '203.0.113.0'];
        yield 'IPv4 already masked' => ['203.0.113.0', '203.0.113.0'];
        yield 'IPv4 loopback' => ['127.0.0.1', '127.0.0.0'];
        yield 'IPv4 first octet only differs' => ['10.9.8.7', '10.9.8.0'];
        yield 'IPv6' => ['2001:db8:abcd:12::1', '2001:db8:abcd::'];
        yield 'IPv6 full form' => ['2001:0db8:abcd:0012:0000:0000:0000:0001', '2001:db8:abcd::'];
        yield 'IPv6 whose fourth group is the first dropped one' => ['2001:db8:abcd:ffff:ffff:ffff:ffff:ffff', '2001:db8:abcd::'];
        yield 'IPv6 shorter than 48 bits' => ['2001:db8::1', '2001:db8::'];
        yield 'IPv6 loopback' => ['::1', '::'];
        yield 'IPv6 upper case' => ['2001:DB8:ABCD:12::1', '2001:db8:abcd::'];
        yield 'IPv4-mapped IPv6' => ['::ffff:203.0.113.45', '203.0.113.0'];
        yield 'IPv4-mapped IPv6 in hex' => ['::ffff:cb00:712d', '203.0.113.0'];
        yield 'IPv6 that only starts like a mapped one' => ['::fffe:203.0.113.45', '::'];
        yield 'IPv6 with ffff in the wrong place' => ['1::ffff:203.0.113.45', '1::'];
    }

    #[DataProvider('maskedAddresses')]
    public function testMaskedKeepsTheNetworkPartOnly(string $ip, string $expected): void
    {
        self::assertSame($expected, IpMode::Masked->apply($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['not an ip'];
        yield 'a host name' => ['example.com'];
        yield 'out of range octet' => ['256.1.1.1'];
        yield 'too few octets' => ['1.2.3'];
        yield 'with a port' => ['203.0.113.45:8080'];
        yield 'with a prefix length' => ['203.0.113.0/24'];
        yield 'with a leading space' => [' 203.0.113.45'];
        yield 'with a trailing newline' => ["203.0.113.45\n"];
        yield 'leading zeros' => ['203.000.113.045'];
        yield 'IPv6 with a zone' => ['fe80::1%eth0'];
        yield 'IPv6 in brackets' => ['[2001:db8::1]'];
        yield 'two addresses' => ['203.0.113.45, 198.51.100.7'];
        yield 'an injection attempt' => ["1.2.3.4'; DROP TABLE x; --"];
        yield 'unknown' => ['unknown'];
    }

    #[DataProvider('invalid')]
    public function testAnythingThatIsNotAValidAddressIsNeverStored(string $ip): void
    {
        self::assertNull(IpMode::Masked->apply($ip));
        self::assertNull(IpMode::Full->apply($ip));
        self::assertNull(IpMode::Off->apply($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function valid(): iterable
    {
        yield 'IPv4' => ['203.0.113.45'];
        yield 'IPv6' => ['2001:db8:abcd:12::1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv4-mapped IPv6' => ['::ffff:203.0.113.45'];
        yield 'IPv6 upper case' => ['2001:DB8::A'];
    }

    #[DataProvider('valid')]
    public function testFullKeepsAValidAddressAsGiven(string $ip): void
    {
        self::assertSame($ip, IpMode::Full->apply($ip));
    }

    #[DataProvider('valid')]
    public function testOffKeepsNothing(string $ip): void
    {
        self::assertNull(IpMode::Off->apply($ip));
    }

    public function testNoAddressIsNoAddressInEveryMode(): void
    {
        foreach (IpMode::cases() as $mode) {
            self::assertNull($mode->apply(null), $mode->name);
        }
    }

    public function testTheModesHaveTheConfigurationNames(): void
    {
        self::assertSame(['off', 'masked', 'full'], array_map(static fn(IpMode $m): string => $m->value, IpMode::cases()));
    }
}
