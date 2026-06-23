<?php

namespace ErnestDefoe\Federation\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Verifies an inbound HTTP Signature against its actor's published public key.
 *
 * On success it returns the verified keyId WITHOUT the #fragment — i.e. the actor
 * URI that actually signed the request. Callers must compare that against any
 * actor claimed in the request body before acting on it, otherwise a server
 * holding key A could sign activities claiming to originate from actor B.
 */
class SignatureVerifier
{
    public function __construct(
        protected ActorFetcher $fetcher,
    ) {}

    /**
     * @return string|null the verified signing actor URI (keyId minus #fragment),
     *                     or null when the signature is missing/invalid
     */
    public function verify(ServerRequestInterface $request, string $rawBody): ?string
    {
        $sigHeader = $request->getHeaderLine('Signature');
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
            if ($h === '(request-target)') {
                $lines[] = '(request-target): '.strtolower($request->getMethod()).' '.$request->getRequestTarget();
            } elseif ($h === 'digest') {
                $lines[] = 'digest: '.$request->getHeaderLine('Digest');
            } else {
                $lines[] = $h.': '.$request->getHeaderLine($h);
            }
        }

        if (str_contains($params['headers'], 'digest')) {
            $expected = 'SHA-256='.base64_encode(hash('sha256', $rawBody, true));
            if (! hash_equals($expected, $request->getHeaderLine('Digest'))) {
                return null;
            }
        }

        $ok = openssl_verify(implode("\n", $lines), base64_decode($params['signature']), $pem, OPENSSL_ALGO_SHA256) === 1;
        if (! $ok) {
            return null;
        }

        // Return the signing actor URI (the key owner if published, else keyId).
        $owner = (string) ($actor['publicKey']['owner'] ?? $actor['id'] ?? $params['keyId']);

        return strtok($owner, '#') ?: $owner;
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
