<?php

namespace ErnestDefoe\Federation\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /federation/users/{id}/inbox — a member's inbox. */
class UserInboxController extends AbstractFederationController
{
    use HandlesInbox;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->processInbox($request, $this->localMember($request));
    }
}
