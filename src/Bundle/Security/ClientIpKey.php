<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

/**
 * @internal The rate-limit bucket of a client address (rate_limit.per: ip): an IPv4 address is its own bucket, an IPv6
 * address shares one with the rest of its /64 (a visitor owns a whole /64 and can rotate inside it, so a bucket per
 * address would limit nothing). An IPv4-mapped IPv6 address (::ffff:203.0.113.45) is the IPv4 address it carries. No
 * address, or text that is not an IP address, has no bucket (null): such an actor is not limited by IP.
 * The key is only handed to the rate limiter, which hashes it into its storage key; it is never logged or stored by Fuzzphony.
 */
final class ClientIpKey
{
    public static function of(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = (string) inet_pton($ip); // a valid address always packs
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
