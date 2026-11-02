<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\FederationFollower;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/followers — the community's (paged) followers collection. */
class FollowersController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->followersCollection(
            $request,
            FederationFollower::query()->whereNull('user_id'),
            $this->settings->base().'/federation/followers',
        );
    }
}
