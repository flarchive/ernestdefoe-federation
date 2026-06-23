<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\FederationFollower;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/followers — that member's followers collection. */
class UserFollowersController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        $user = $this->localMember($request);

        $items = FederationFollower::where('user_id', $user->id)->pluck('actor')->all();

        return $this->ap([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => Federation::base().'/federation/users/'.$user->id.'/followers',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ]);
    }
}
