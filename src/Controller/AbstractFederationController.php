<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shared helpers for the ActivityPub endpoints. All 404 unless enabled. */
abstract class AbstractFederationController implements RequestHandlerInterface
{
    /** Followers returned per OrderedCollectionPage. */
    protected const PAGE_SIZE = 100;

    public function __construct(
        protected Settings $settings,
    ) {}

    /** Abort with 404 when federation is switched off in admin. */
    protected function guard(): void
    {
        if (! $this->settings->enabled()) {
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

    /** The matched {id} path segment, read from the route parameters. */
    protected function routeId(ServerRequestInterface $request): int
    {
        return (int) ($request->getAttribute('routeParameters')['id'] ?? 0);
    }

    /** Resolve the {id} route parameter to a non-federated local member, or 404. */
    protected function localMember(ServerRequestInterface $request): User
    {
        $id = $this->routeId($request);
        $user = $id > 0 ? User::find($id) : null;
        if (! $user || Fed::isFederated($user)) {
            throw new RouteNotFoundException;
        }

        return $user;
    }

    /**
     * A paginated followers collection (audit: never load every follower URI into
     * memory). With no ?page it returns the OrderedCollection stub (totalItems +
     * a first link); with ?page=N it returns the OrderedCollectionPage.
     *
     * @param  Builder  $query  the followers query, already scoped (community/member)
     */
    protected function followersCollection(ServerRequestInterface $request, Builder $query, string $id): ResponseInterface
    {
        $page = (int) ($request->getQueryParams()['page'] ?? 0);
        $total = (clone $query)->count();

        if ($page < 1) {
            return $this->ap([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $id,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $id.'?page=1',
            ]);
        }

        // Flarum core does not ship illuminate/pagination, so avoid paginate();
        // forPage() is a plain offset/limit on the query builder.
        $items = (clone $query)->orderBy('id')->forPage($page, self::PAGE_SIZE)->pluck('actor')->all();

        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id.'?page='.$page,
            'type' => 'OrderedCollectionPage',
            'partOf' => $id,
            'totalItems' => $total,
            'orderedItems' => $items,
        ];
        if ($page * self::PAGE_SIZE < $total) {
            $doc['next'] = $id.'?page='.($page + 1);
        }
        if ($page > 1) {
            $doc['prev'] = $id.'?page='.($page - 1);
        }

        return $this->ap($doc);
    }

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;
}
