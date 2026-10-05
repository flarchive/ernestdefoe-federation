<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Service\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/actor — a member actor (a Person). */
class UserActorController extends AbstractFederationController
{
    private const NEW_KEY_WINDOW = 600; // seconds

    private const NEW_KEYS_PER_WINDOW = 60;

    public function __construct(
        Settings $settings,
        Fed $fed,
        protected DocumentBuilder $documents,
        protected Cache $cache,
    ) {
        parent::__construct($settings, $fed);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        $user = $this->localMember($request);

        // Serving a member's actor creates their RSA keypair on first request.
        // Cap how many NEW keypairs anonymous requests can create per window so
        // walking every member id is not free CPU and database writes; members
        // who already have a key are never affected.
        if (! $user->federationData?->ap_public_key) {
            $bucket = 'federation:new-member-keys:'.intdiv(time(), self::NEW_KEY_WINDOW);
            $this->cache->add($bucket, 0, self::NEW_KEY_WINDOW);
            if ($this->cache->increment($bucket) > self::NEW_KEYS_PER_WINDOW) {
                return new EmptyResponse(503, ['Retry-After' => (string) self::NEW_KEY_WINDOW]);
            }
        }

        return $this->ap($this->documents->userActor($user));
    }
}
