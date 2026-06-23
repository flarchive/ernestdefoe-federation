<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Federation;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Outbound HTTP: fetches (and caches) remote actor documents and delivers signed
 * activities to remote inboxes. Every destination is screened by {@see UrlGuard}
 * before a request is made, so a hostile keyId/actor/inbox cannot point us at an
 * internal address (SSRF).
 */
class ActorFetcher
{
    public function __construct(
        protected HttpSigner $signer,
        protected UrlGuard $guard,
        protected Cache $cache,
        protected LoggerInterface $log,
    ) {}

    /** Fetch (and cache) a remote actor document. Accepts an actor or key URL. */
    public function fetchActor(string $url): ?array
    {
        $url = strtok($url, '#') ?: $url; // strip #main-key fragment

        if (! $this->guard->isAllowed($url)) {
            $this->log->debug('[federation] refused to fetch unsafe actor URL: '.$url);

            return null;
        }

        return $this->cache->remember('federation:actor:'.sha1($url), 3600, function () use ($url) {
            try {
                $headers = $this->signer->signHeaders(null, 'get', $url);
                $headers['Accept'] = Federation::CTYPE;
                $res = (new Client(['timeout' => 8]))->get($url, ['headers' => $headers, 'http_errors' => false]);
                if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                    return json_decode((string) $res->getBody(), true) ?: null;
                }
            } catch (\Throwable $e) {
                $this->log->debug('[federation] fetchActor failed: '.$e->getMessage());
            }

            return null;
        });
    }

    /** POST a signed activity to a single inbox. Returns the HTTP status, or 0. */
    public function deliver(?User $signer, string $inbox, array $activity): int
    {
        if (! $this->guard->isAllowed($inbox)) {
            $this->log->debug('[federation] refused to deliver to unsafe inbox: '.$inbox);

            return 0;
        }

        try {
            $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = $this->signer->signHeaders($signer, 'post', $inbox, $body);
            $res = (new Client(['timeout' => 12]))->post($inbox, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
            ]);

            return $res->getStatusCode();
        } catch (\Throwable $e) {
            $this->log->debug('[federation] delivery failed to '.$inbox.': '.$e->getMessage());

            return 0;
        }
    }
}
