<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Service\Settings;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /federation/users/{id}/inbox — a member's inbox. */
class UserInboxController extends AbstractFederationController
{
    use HandlesInbox;

    public function __construct(Settings $settings, Fed $fed, Bus $bus)
    {
        parent::__construct($settings, $fed);
        $this->bus = $bus;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->processInbox($request, $this->localMember($request));
    }
}
