<?php

namespace ErnestDefoe\Federation\Tests\integration;

use ErnestDefoe\Federation\Service\ActorFetcher;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Store;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;

abstract class FederationTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    public const REMOTE = 'https://remote.example/users/alice';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...$this->extensions());
        FakeActorFetcher::$actors = [];
        FakeActorFetcher::$deliveries = [];
    }

    /** @return list<string> extension ids, dependencies first */
    protected function extensions(): array
    {
        return ['ernestdefoe-federation'];
    }

    protected function enable(): void
    {
        $this->setting('ernestdefoe-federation.enabled', '1');
        $this->setting('ernestdefoe-federation.username', 'garage');
    }

    /** Boots the app with the fake fediverse in place and an empty cache. */
    protected function fakeFediverse(): void
    {
        $container = $this->app()->getContainer();
        $container->instance(ActorFetcher::class, new FakeActorFetcher());
        $container->make(Store::class)->flush();
    }

    protected function get(string $path, array $query = [], ?int $actor = null): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query));
    }

    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * A remote actor with its own key pair, served by the fake fetcher.
     *
     * @return string the private key, to sign with
     */
    protected function remoteActor(string $url = self::REMOTE): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        FakeActorFetcher::$actors[$url] = [
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'inbox' => $url.'/inbox',
            'publicKey' => ['id' => $url.'#main-key', 'owner' => $url, 'publicKeyPem' => $public],
        ];

        return $private;
    }

    /** POST an activity to an inbox, signed the way Mastodon signs it. */
    protected function postSigned(string $path, array $activity, string $privateKey, string $keyId = self::REMOTE.'#main-key', ?string $date = null): ResponseInterface
    {
        $body = json_encode($activity, JSON_UNESCAPED_SLASHES);
        $date ??= gmdate('D, d M Y H:i:s').' GMT';
        $digest = 'SHA-256='.base64_encode(hash('sha256', $body, true));

        $signed = "(request-target): post $path\nhost: localhost\ndate: $date\ndigest: $digest";
        openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $this->postRaw($path, $body, [
            'Host' => 'localhost',
            'Date' => $date,
            'Digest' => $digest,
            'Signature' => 'keyId="'.$keyId.'",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="'.base64_encode($signature).'"',
        ]);
    }

    protected function postRaw(string $path, string $body, array $headers): ResponseInterface
    {
        $request = $this->request('POST', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Content-Type', 'application/activity+json'));
    }
}
