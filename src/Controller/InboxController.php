<?php

namespace ErnestDefoe\Federation\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /federation/inbox — the community's shared inbox. */
class InboxController extends AbstractFederationController
{
    use HandlesInbox;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->processInbox($request, null);
    }
}
