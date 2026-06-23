<?php

namespace ErnestDefoe\Federation\Service;

/**
 * Guards every outbound federation request against SSRF.
 *
 * The keyId/actor/inbox URLs we dereference all come from untrusted remote input
 * (a signed inbox POST can name any URL). Without validation an attacker can make
 * the server GET/POST internal addresses — cloud metadata (169.254.169.254),
 * loopback, RFC-1918 ranges, etc. This rejects anything that is not https:// and
 * anything that resolves to a non-publicly-routable address.
 *
 * Set FEDERATION_ALLOW_PRIVATE=1 to disable the private-range check for local
 * development against a federation peer on a private network.
 */
class UrlGuard
{
    /** True when the URL is safe to dereference from the server. */
    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }

        if (getenv('FEDERATION_ALLOW_PRIVATE') === '1') {
            return true;
        }

        $host = trim($host, '[]'); // strip IPv6 brackets
        $ips = $this->resolve($host);
        if ($ips === []) {
            return false; // cannot verify the destination → treat as unsafe
        }
        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] resolved IP literals for $host (the literal itself if it is one) */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
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
        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== '' && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /** Public = not private (RFC-1918 / fc00::/7) and not reserved (loopback, link-local, …). */
    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
