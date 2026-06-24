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
