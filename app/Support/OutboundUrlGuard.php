<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Host checks for outbound webhook destinations.
 *
 * Subscription target URLs are super-admin input, but the application is
 * the one making the request — a URL pointing at loopback, the RFC1918
 * ranges, the link-local metadata service or another non-routable host
 * turns the webhook sender into an SSRF pivot into the private network.
 * Used by the webhook subscription form to reject such targets up front.
 */
class OutboundUrlGuard
{
    /**
     * Single-label / suffix hosts that only exist on the local machine or
     * inside a trusted network.
     *
     * @var array<int, string>
     */
    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal'];

    /**
     * Whether the URL's host is publicly routable.
     *
     * Hostnames are resolved and checked against the same rules; a hostname
     * that cannot be resolved is allowed through here (the delivery will
     * fail on its own and surface in the webhook delivery ledger).
     */
    public static function isPubliclyRoutable(string $url): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        // Strip the brackets of IPv6 literals ([::1] → ::1).
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost') {
            return false;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::ipIsPubliclyRoutable($host);
        }

        $resolved = @gethostbyname($host);

        if (is_string($resolved) && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
            return self::ipIsPubliclyRoutable($resolved);
        }

        return true;
    }

    /**
     * Whether a concrete IP address is publicly routable.
     *
     * FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE cover loopback, RFC1918,
     * link-local and the IPv6 equivalents; carrier-grade NAT (100.64/10),
     * multicast (224/4) and the IPv4-mapped and link-local IPv6 forms are
     * checked explicitly because the filter misses them.
     */
    public static function ipIsPubliclyRoutable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = (int) sprintf('%u', (string) ip2long($ip));

            // 100.64.0.0/10 (carrier-grade NAT) and 224.0.0.0/4 (multicast).
            if (($long & 0xFFC00000) === 0x64400000 || ($long & 0xF0000000) === 0xE0000000) {
                return false;
            }

            return true;
        }

        $bin = @inet_pton($ip);

        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }

        // ::ffff:x.y.z.w — re-check the embedded IPv4 address.
        if (substr($bin, 0, 10) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            && substr($bin, 10, 2) === "\xff\xff") {
            return self::ipIsPubliclyRoutable(long2ip((int) sprintf('%u', unpack('N', substr($bin, 12, 4))[1])));
        }

        // fe80::/10 (link-local).
        if ($bin[0] === "\xfe" && (ord($bin[1]) & 0xC0) === 0x80) {
            return false;
        }

        return true;
    }
}
