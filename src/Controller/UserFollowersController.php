<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\FederationFollower;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/followers — a member's (paged) followers collection. */
class UserFollowersController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        $user = $this->localMember($request);

        return $this->followersCollection(
            $request,
            FederationFollower::query()->where('user_id', $user->id),
            $this->settings->base().'/federation/users/'.$user->id.'/followers',
        );
    }
}
