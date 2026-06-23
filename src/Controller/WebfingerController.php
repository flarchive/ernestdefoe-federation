<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Http\Exception\RouteNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /.well-known/webfinger — resolves acct:{name}@{host} to an actor. */
class WebfingerController extends AbstractFederationController
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

        $resource = (string) Arr::get($request->getQueryParams(), 'resource', '');
        if (! preg_match('/^acct:([^@]+)@(.+)$/i', $resource, $m) || strcasecmp($m[2], $this->settings->host()) !== 0) {
            throw new RouteNotFoundException;
        }

        $resolved = $this->settings->resolveUsername($m[1]);
        if ($resolved === null) {
            throw new RouteNotFoundException;
        }

        $doc = $resolved['type'] === 'user'
            ? $this->documents->userWebfinger($resolved['user'])
            : $this->documents->webfinger();

        return $this->ap($doc, 'application/jrd+json');
    }
}
