<?php

namespace ErnestDefoe\Federation\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /.well-known/nodeinfo — points to the NodeInfo 2.0 document. */
class NodeInfoController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->ap([
            'links' => [[
                'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                'href' => $this->settings->base().'/nodeinfo/2.0',
            ]],
        ], 'application/json');
    }
}
