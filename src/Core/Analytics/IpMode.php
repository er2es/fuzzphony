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
     * address (anything else would be free text in a personal-data column). Full keeps the valid address in its canonical
     * form (2001:DB8::1 and 2001:0db8:0:0:0:0:0:1 are one value). Masked zeroes the last octet of an IPv4 address and keeps
     * the first 48 bits of an IPv6 address (203.0.113.45 becomes 203.0.113.0, 2001:db8:abcd:12::1 becomes 2001:db8:abcd::);
     * an IPv4-mapped IPv6 address (::ffff:203.0.113.45) is masked as the IPv4 address it carries, and so is the IPv4 address
     * a 6to4 address (2002::/16) embeds in its bits 16 to 48 (2002:cb00:712d::1 becomes 2002:cb00:7100::): a plain /48 cut
     * would keep that whole address.
     */
    public function apply(?string $ip): ?string
    {
        if ($this === self::Off || $ip === null || filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = (string) inet_pton($ip); // a valid address always packs
        if ($this === self::Full) {
            return (string) inet_ntop($packed);
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        // 6to4: the client's IPv4 address is bits 16 to 48, so the last octet of it (byte 5) goes, not only what follows the /48
        $kept = match (true) {
            strlen($packed) === 4 => 3,
            str_starts_with($packed, "\x20\x02") => 5,
            default => 6,
        };

        return (string) inet_ntop(str_pad(substr($packed, 0, $kept), strlen($packed), "\0"));
    }
}
