<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Flarum\Http\Exception\RouteNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /.well-known/webfinger — resolves acct:{name}@{host} to an actor. */
class WebfingerController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $resource = (string) Arr::get($request->getQueryParams(), 'resource', '');
        if (! preg_match('/^acct:([^@]+)@(.+)$/i', $resource, $m) || strcasecmp($m[2], Federation::host()) !== 0) {
            throw new RouteNotFoundException;
        }

        $resolved = Federation::resolveUsername($m[1]);
        if ($resolved === null) {
            throw new RouteNotFoundException;
        }

        $doc = $resolved['type'] === 'user'
            ? Federation::userWebfinger($resolved['user'])
            : Federation::webfinger();

        return $this->ap($doc, 'application/jrd+json');
    }
}
