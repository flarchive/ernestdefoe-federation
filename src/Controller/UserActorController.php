<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/actor — a member actor (a Person). */
class UserActorController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->ap(Federation::userActor($this->localMember($request)));
    }
}
