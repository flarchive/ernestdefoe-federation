<?php

namespace ErnestDefoe\Federation\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Verifies an inbound HTTP Signature against its actor's published public key.
 *
 * The core works on raw request parts ({@see verifyParts}) so it can run inside a
 * queued job (the request object itself isn't serializable); {@see verify} is a
 * thin adapter for a live PSR-7 request.
 *
 * On success it returns the verified keyId WITHOUT the #fragment — i.e. the actor
 * URI that actually signed the request. Callers must compare that against any
 * actor claimed in the request body before acting on it.
 */
class SignatureVerifier
{
    /**
     * Max allowed difference between the signed Date header and when the request
     * was RECEIVED. The Date is part of the signed string, so it can't be altered
     * without invalidating the signature; rejecting stale dates blocks replay.
     * Compared against receipt time (captured before queueing), not job-run time,
     * so queue latency never rejects valid traffic. 300s tolerates clock skew —
     * the window most AP implementations use.
     */
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        protected ActorFetcher $fetcher,
    ) {}

    /**
     * @param  array<string,string>  $headers  lower-cased header name => value
     * @param  int  $receivedAt  unix time the request arrived (not when verified)
     * @return string|null the verified signing actor URI, or null when invalid
     */
    public function verifyParts(string $method, string $requestTarget, array $headers, string $rawBody, int $receivedAt): ?string
    {
        $sigHeader = $headers['signature'] ?? '';
        if ($sigHeader === '') {
            return null;
        }
        $params = $this->parseSignature($sigHeader);
        if (empty($params['keyId']) || empty($params['headers']) || empty($params['signature'])) {
            return null;
        }

        $actor = $this->fetcher->fetchActor($params['keyId']);
        $pem = $actor['publicKey']['publicKeyPem'] ?? null;
        if (! $pem) {
            return null;
        }

        $lines = [];
        foreach (explode(' ', $params['headers']) as $h) {
            $lines[] = $h === '(request-target)'
                ? '(request-target): '.strtolower($method).' '.$requestTarget
                : $h.': '.($headers[strtolower($h)] ?? '');
        }

        // Inbox POSTs always carry a body; REQUIRE it to be integrity-covered.
        // Without a signed digest a proxy/TLS-terminator could swap the activity
        // (Follow→Delete, actor URIs, …) while the signature still verifies.
        if (! str_contains($params['headers'], 'digest')) {
            return null;
        }
        $expected = 'SHA-256='.base64_encode(hash('sha256', $rawBody, true));
        if (! hash_equals($expected, $headers['digest'] ?? '')) {
            return null;
        }

        if (openssl_verify(implode("\n", $lines), base64_decode($params['signature']), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        // Anti-replay: the Date is signed, so reject anything outside the window.
        if (! $this->dateIsFresh($headers['date'] ?? '', $receivedAt)) {
            return null;
        }

        // The signing actor URI (key owner if published, else keyId), normalised.
        $owner = (string) ($actor['publicKey']['owner'] ?? $actor['id'] ?? $params['keyId']);

        return trim(strtok($owner, '#') ?: $owner);
    }

    /** Adapter for a live request: normalise headers, then verify the parts. */
    public function verify(ServerRequestInterface $request, string $rawBody): ?string
    {
        return $this->verifyParts(
            strtolower($request->getMethod()),
            $request->getRequestTarget(),
            self::normaliseHeaders($request),
            $rawBody,
            time(),
        );
    }

    /** @return array<string,string> lower-cased header name => comma-joined value */
    public static function normaliseHeaders(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    /** True when the signed Date header is present and within the skew window. */
    private function dateIsFresh(string $date, int $receivedAt): bool
    {
        if ($date === '') {
            return false;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }

        return abs($receivedAt - $ts) <= self::MAX_CLOCK_SKEW_SECONDS;
    }

    /** @return array<string,string> */
    private function parseSignature(string $header): array
    {
        $out = [];
        preg_match_all('/(\w+)="([^"]*)"/', $header, $m, PREG_SET_ORDER);
        foreach ($m as $pair) {
            $out[$pair[1]] = $pair[2];
        }

        return $out;
    }
}
