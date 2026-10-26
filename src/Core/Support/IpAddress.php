<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/**
 * @internal The one way a client address is cleaned before it is used as a rate-limit key (ClientIpKey) or stored
 * in the search log (IpMode): surrounding whitespace, a port after an IPv4 address (1.2.3.4:80), brackets and a port
 * around an IPv6 address ([2001:db8::1]:443) and a zone id (fe80::1%eth0) go. What is still no IP address is none.
 */
final class IpAddress
{
    /** The cleaned text; it is an IP address only when filter_var() says so. */
    public static function normalise(string $ip): string
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

    /** The cleaned, valid address, or null. */
    public static function valid(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = self::normalise($ip);

        return filter_var($ip, \FILTER_VALIDATE_IP) === false ? null : $ip;
    }
}
