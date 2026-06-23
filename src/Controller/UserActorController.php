<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/actor — a member actor (a Person). */
class UserActorController extends AbstractFederationController
{
    public function __construct(
        Settings $settings,
        protected DocumentBuilder $documents,
    ) {
        parent::__construct($settings);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->ap($this->documents->userActor($this->localMember($request)));
    }
}
