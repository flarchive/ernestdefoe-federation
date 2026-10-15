<?php

namespace ErnestDefoe\Federation\Service;

/**
 * Guards every outbound federation request against SSRF.
 *
 * The keyId/actor/inbox URLs we dereference all come from untrusted remote input
 * (a signed inbox POST can name any URL). Without validation an attacker can make
 * the server GET/POST internal addresses — cloud metadata (169.254.169.254),
 * loopback, RFC-1918 ranges, etc.
 *
 * {@see pinnedIp} resolves and validates the host, then returns the exact IP the
 * caller must connect to — so the HTTP client pins that checked address instead
 * of re-resolving DNS on connect (which a zero-TTL record could flip to an
 * internal IP between our check and the connection: DNS rebinding).
 *
 * Set FEDERATION_ALLOW_PRIVATE=1 to disable the checks for local development
 * against a peer on a private network.
 */
class UrlGuard
{
    /**
     * @return string|null  a validated public IP to pin the connection to;
     *                      '' = allowed without pinning (dev override);
     *                      null = the URL must not be dereferenced
     */
    public function pinnedIp(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }

        if (getenv('FEDERATION_ALLOW_PRIVATE') === '1') {
            return ''; // dev: allowed, let the client resolve normally
        }

        $host = trim($host, '[]'); // strip IPv6 brackets
        $ips = $this->resolve($host);
        if ($ips === []) {
            return null; // cannot verify the destination → treat as unsafe
        }
        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                return null;
            }
        }

        // Every resolved address is public; pin the first so the actual
        // connection can't be re-pointed at an internal host.
        return $ips[0];
    }

    /** True when the URL is safe to dereference from the server. */
    public function isAllowed(string $url): bool
    {
        return $this->pinnedIp($url) !== null;
    }

    /**
     * @return string[] resolved IP literals for $host (the literal itself if it is one)
     *
     * Only a canonical IP literal or a real DNS name is accepted. Numeric shorthands
     * that are not canonical dotted quads — 0177.0.0.1, 0x7f.1, 2130706433, 127.1 —
     * are refused outright: PHP's own checks do not read them as IPs, but
     * gethostbyname() and curl do, as loopback. There is deliberately no
     * gethostbyname() fallback, for the same reason.
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $name = strtolower(rtrim($host, '.'));
        if ($name === '' || ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $name)) {
            return []; // not a plain (punycode) hostname
        }
        // A last label that is all digits or 0x-hex makes URL parsers read the whole
        // host as an IPv4 address in shorthand form, so it is never a real name.
        $labels = explode('.', $name);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', end($labels))) {
            return [];
        }

        $ips = [];
        $records = @dns_get_record($name, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Public = not private (RFC-1918 / fc00::/7), not reserved (loopback,
     * link-local, …) and none of the special ranges PHP's filter lets through but
     * which can reach internal hosts: carrier-grade NAT, IETF/benchmark blocks,
     * multicast, and IPv6 prefixes that embed an IPv4 address (NAT64, 6to4).
     */
    private function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blocked = [
            '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4',
            '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32', '2001:db8::/32', '100::/64', 'ff00::/8',
        ];
        foreach ($blocked as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
