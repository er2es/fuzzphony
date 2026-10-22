<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

/**
 * @internal The rate-limit bucket of a client address (rate_limit.per: ip): an IPv4 address is its own bucket, an IPv6
 * address shares one with the rest of its /64 (a visitor owns a whole /64 and can rotate inside it, so a bucket per
 * address would limit nothing). An address that EMBEDS an IPv4 address is that IPv4 address, whatever the spelling:
 * IPv4-mapped (::ffff:203.0.113.45), IPv4-compatible (::203.0.113.45, ::/96), NAT64 (64:ff9b::203.0.113.45,
 * 64:ff9b::/96: behind a NAT64 gateway one /64 holds every IPv4 visitor of the internet, so a /64 bucket would be
 * one bucket for all of them) and 6to4 (2002:cb00:712d::1, 2002::/16: the IPv4 address is bits 16 to 48, and the
 * visitor owns the whole /48 behind it, 65 536 /64s).
 *
 * A value that is not exactly an IP address is normalised when that is safe: surrounding whitespace, a port after an
 * IPv4 address (1.2.3.4:80), brackets and a port around an IPv6 address ([2001:db8::1]:443) and a zone id
 * (fe80::1%eth0). What is still no IP address has no bucket (null): such an actor is not limited by IP.
 * RateLimitGuard keys the limiter with an HMAC of the key, so the cache pool never holds the address; Fuzzphony never
 * logs or stores it.
 */
final class ClientIpKey
{
    public static function of(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = self::normalise($ip);
        if (filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = (string) inet_pton($ip); // a valid address always packs
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }
        $embedded = match (true) {
            str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") => substr($packed, 12), // ::ffff:a.b.c.d
            str_starts_with($packed, str_repeat("\0", 12)) => substr($packed, 12), // ::a.b.c.d (also :: and ::1)
            str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8)) => substr($packed, 12), // 64:ff9b::a.b.c.d
            str_starts_with($packed, "\x20\x02") => substr($packed, 2, 4), // 2002:AABB:CCDD::
            default => null,
        };
        if ($embedded !== null) {
            return (string) inet_ntop($embedded);
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** True when $ip holds something (not only whitespace) but no key can be made of it. */
    public static function isUnparseable(?string $ip): bool
    {
        return $ip !== null && trim($ip) !== '' && self::of($ip) === null;
    }

    private static function normalise(string $ip): string
    {
        $ip = trim($ip);
        if (preg_match('/^\[([^\]]+)\](?::\d{1,5})?$/', $ip, $m) === 1) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (str_contains($ip, ':') && ($zone = strpos($ip, '%')) !== false) {
            $ip = substr($ip, 0, $zone);
        }

        return $ip;
    }
}
