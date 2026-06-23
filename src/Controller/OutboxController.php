<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Flarum\Discussion\Discussion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/outbox — recent public discussions as Create activities. */
class OutboxController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $items = $this->publicDiscussions()
            ->map(fn (Discussion $d) => Federation::createActivityForDiscussion($d))
            ->all();

        return $this->ap([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => Federation::base().'/federation/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => $this->base()->count(),
            'orderedItems' => $items,
        ]);
    }

    protected function base()
    {
        return Discussion::query()
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->whereHas('user', fn ($q) => $q->where('is_federated', false));
    }

    protected function publicDiscussions()
    {
        return $this->base()->with(['firstPost', 'user'])->latest()->limit(20)->get();
    }
}
