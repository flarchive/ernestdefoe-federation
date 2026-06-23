<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\FederationFollower;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/followers — the community's followers collection. */
class FollowersController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $items = FederationFollower::whereNull('user_id')->pluck('actor')->all();

        return $this->ap([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => Federation::base().'/federation/followers',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ]);
    }
}
