<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Federation;
use Flarum\User\User;

/**
 * Builds HTTP Signature headers (draft-cavage, as Mastodon uses) for an outbound
 * request, signing as a member ($signer) or the community ($signer === null).
 */
class HttpSigner
{
    public function __construct(
        protected Settings $settings,
        protected KeyManager $keys,
    ) {}

    /** @return array<string,string> headers to send (Host, Date, [Digest], Signature) */
    public function signHeaders(?User $signer, string $method, string $url, ?string $body = null): array
    {
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $method = strtolower($method);

        $headers = ['Host' => $host, 'Date' => $date];
        $signedHeaders = ['(request-target)', 'host', 'date'];
        $lines = [
            '(request-target): '.$method.' '.$path,
            'host: '.$host,
            'date: '.$date,
        ];

        if ($body !== null) {
            $digest = 'SHA-256='.base64_encode(hash('sha256', $body, true));
            $headers['Digest'] = $digest;
            $headers['Content-Type'] = Federation::CTYPE; // sent, but NOT signed
            $signedHeaders[] = 'digest';
            $lines[] = 'digest: '.$digest;
        }

        $priv = $signer ? $this->keys->userKeys($signer)['private'] : $this->keys->communityKeys()['private'];
        $keyId = ($signer ? $this->settings->userActorUrl($signer) : $this->settings->actorUrl()).'#main-key';
        openssl_sign(implode("\n", $lines), $sig, $priv, OPENSSL_ALGO_SHA256);
        $headers['Signature'] = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $signedHeaders),
            base64_encode((string) $sig),
        );

        return $headers;
    }
}
