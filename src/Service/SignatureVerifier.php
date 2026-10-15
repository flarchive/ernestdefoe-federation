<?php

namespace ErnestDefoe\Federation\Service;

use Illuminate\Contracts\Cache\Repository as Cache;
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

    private const REQUIRED_HEADERS = ['(request-target)', 'host', 'date', 'digest'];

    public function __construct(
        protected ActorFetcher $fetcher,
        protected Cache $cache,
    ) {
    }

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

        // The parts that make a signature mean something must all be covered:
        // which endpoint (request-target), which server (host — else a delivery
        // to another forum replays here), when (date — else a captured request
        // replays forever with a fresh unsigned Date) and what (digest — else the
        // body can be swapped). Every mainstream server signs all four.
        $signed = array_map('strtolower', preg_split('/\s+/', trim($params['headers'])) ?: []);
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $signed, true)) {
                return null;
            }
        }

        // The signed digest must match the body actually received.
        $expected = 'SHA-256='.base64_encode(hash('sha256', $rawBody, true));
        if (! hash_equals($expected, $headers['digest'] ?? '')) {
            return null;
        }

        // Anti-replay: the Date is signed (required above), so reject anything
        // outside the window. Both cheap checks run before the actor fetch so a
        // junk request never causes an outbound request.
        if (! $this->dateIsFresh($headers['date'] ?? '', $receivedAt)) {
            return null;
        }

        $actor = $this->fetcher->fetchActor($params['keyId']);
        $pem = $actor['publicKey']['publicKeyPem'] ?? null;
        if (! $pem) {
            return null;
        }

        $lines = [];
        foreach ($signed as $h) {
            $lines[] = $h === '(request-target)'
                ? '(request-target): '.strtolower($method).' '.$requestTarget
                : $h.': '.($headers[strtolower($h)] ?? '');
        }

        if (openssl_verify(implode("\n", $lines), base64_decode($params['signature']), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $owner = $this->keyOwner($params['keyId'], $actor, $pem);

        // A valid signature is accepted once: the same signed request replayed
        // inside the Date window is dropped. Remembered for twice the window so
        // a replay at either edge is still recognised.
        if ($owner !== null
            && ! $this->cache->add('federation:sig:'.sha1($params['keyId']."\n".$params['signature']), 1, 2 * self::MAX_CLOCK_SKEW_SECONDS)) {
            return null;
        }

        return $owner;
    }

    /**
     * The actor that really owns the key, or null when the key document claims an
     * owner it cannot prove. Without this, anyone could publish their own key with
     * `owner` set to somebody else's actor and act as them.
     *
     * - The owner must be on the same origin as the keyId (a server can only speak
     *   for its own accounts).
     * - When the keyId was the owner's own actor document (Mastodon, Lemmy:
     *   …/users/alice#main-key), that is proof enough. Otherwise (a separate key
     *   document, as GoToSocial publishes) the owner's actor is fetched and must
     *   list this same key.
     */
    private function keyOwner(string $keyId, array $keyDoc, string $pem): ?string
    {
        $strip = static fn (string $u): string => trim(strtok($u, '#') ?: $u);
        $keyUrl = $strip($keyId);
        $owner = $strip((string) ($keyDoc['publicKey']['owner'] ?? $keyDoc['id'] ?? $keyUrl));

        if ($owner === '' || ! self::sameOrigin($owner, $keyUrl)) {
            return null;
        }
        $docId = $strip((string) ($keyDoc['id'] ?? $keyUrl));
        if (strcasecmp($owner, $keyUrl) === 0 && strcasecmp($docId, $owner) === 0) {
            return $owner;
        }

        $ownerDoc = $owner === $keyUrl ? $keyDoc : $this->fetcher->fetchActor($owner);
        if (! is_array($ownerDoc) || strcasecmp($strip((string) ($ownerDoc['id'] ?? '')), $owner) !== 0) {
            return null;
        }
        $listedId = (string) ($ownerDoc['publicKey']['id'] ?? '');
        $listedPem = (string) ($ownerDoc['publicKey']['publicKeyPem'] ?? '');
        if (strcasecmp($listedId, $keyId) !== 0 || trim($listedPem) !== trim($pem)) {
            return null;
        }

        return $owner;
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (! is_array($pa) || ! is_array($pb)) {
            return false;
        }

        return strtolower($pa['scheme'] ?? '') === strtolower($pb['scheme'] ?? '')
            && strtolower($pa['host'] ?? '') === strtolower($pb['host'] ?? '')
            && ($pa['port'] ?? null) === ($pb['port'] ?? null)
            && ($pa['host'] ?? '') !== '';
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
