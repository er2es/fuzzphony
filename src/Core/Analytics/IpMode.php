<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/**
 * Whether the search log keeps the client IP of an actor, and how much of it: nothing (the default), the address with
 * its host part cut off, or the whole address. An IP address is personal data: Masked is the mode the documentation
 * recommends. apply() is the one place that decides what is stored.
 */
enum IpMode: string
{
    case Off = 'off';
    case Masked = 'masked';
    case Full = 'full';

    /**
     * What the log stores for $ip: null when the mode is Off, when there is no address or when it is not a valid IP
     * address (anything else would be free text in a personal-data column). Full keeps a valid address as it was given;
     * Masked zeroes the last octet of an IPv4 address and keeps the first 48 bits of an IPv6 address
     * (203.0.113.45 becomes 203.0.113.0, 2001:db8:abcd:12::1 becomes 2001:db8:abcd::); an IPv4-mapped IPv6 address
     * (::ffff:203.0.113.45) is masked as the IPv4 address it carries.
     */
    public function apply(?string $ip): ?string
    {
        if ($this === self::Off || $ip === null || filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if ($this === self::Full) {
            return $ip;
        }
        $packed = (string) inet_pton($ip); // a valid address always packs
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $kept = strlen($packed) === 4 ? 3 : 6;

        return (string) inet_ntop(str_pad(substr($packed, 0, $kept), strlen($packed), "\0"));
    }
}
