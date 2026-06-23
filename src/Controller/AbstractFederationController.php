<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shared helpers for the ActivityPub endpoints. All 404 unless enabled. */
abstract class AbstractFederationController implements RequestHandlerInterface
{
    /** Abort with 404 when federation is switched off in admin. */
    protected function guard(): void
    {
        if (! Federation::enabled()) {
            throw new RouteNotFoundException;
        }
    }

    /** A JSON response with the ActivityPub content type. */
    protected function ap(array $doc, string $contentType = Federation::CTYPE): JsonResponse
    {
        return new JsonResponse(
            $doc,
            200,
            ['Content-Type' => $contentType],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** Resolve a {user} route parameter to a non-federated local member, or 404. */
    protected function localMember(ServerRequestInterface $request): User
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $user = $id > 0 ? User::find($id) : null;
        if (! $user || $user->is_federated) {
            throw new RouteNotFoundException;
        }

        return $user;
    }

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;
}
