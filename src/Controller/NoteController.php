<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\Exception\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/notes/{id} — the AP Note object for a discussion. */
class NoteController extends AbstractFederationController
{
    public function __construct(
        Settings $settings,
        protected Federation $federation,
        protected DocumentBuilder $documents,
    ) {
        parent::__construct($settings);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $id = $this->routeId($request);
        $discussion = $id > 0 ? Discussion::find($id) : null;

        if (! $discussion || ! $this->federation->shouldFederate($discussion)) {
            throw new RouteNotFoundException;
        }

        $discussion->loadMissing('firstPost', 'user');

        return $this->ap(
            ['@context' => 'https://www.w3.org/ns/activitystreams']
            + $this->documents->noteForDiscussion($discussion)
        );
    }
}
