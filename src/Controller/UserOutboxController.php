<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/outbox — that member's recent discussions. */
class UserOutboxController extends AbstractFederationController
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
        $user = $this->localMember($request);

        $base = Discussion::query()
            ->where('user_id', $user->id)
            ->where('is_private', false)
            ->whereNull('hidden_at');

        $items = (clone $base)->with(['firstPost', 'user'])->latest()->limit(20)->get()
            ->map(fn (Discussion $d) => $this->documents->createActivityForDiscussion($d))->all();

        return $this->ap([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->settings->base().'/federation/users/'.$user->id.'/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => $base->count(),
            'orderedItems' => $items,
        ]);
    }
}
