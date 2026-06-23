<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Flarum\Discussion\Discussion;
use Flarum\Http\Exception\RouteNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/notes/{id} — the AP Note object for a discussion. */
class NoteController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $discussion = $id > 0 ? Discussion::find($id) : null;

        if (! $discussion || ! Federation::shouldFederate($discussion)) {
            throw new RouteNotFoundException;
        }

        $discussion->loadMissing('firstPost', 'user');

        return $this->ap(
            ['@context' => 'https://www.w3.org/ns/activitystreams']
            + Federation::noteForDiscussion($discussion)
        );
    }
}
