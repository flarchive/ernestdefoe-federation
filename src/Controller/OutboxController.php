<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/outbox — recent public discussions as Create activities. */
class OutboxController extends AbstractFederationController
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

        $items = $this->publicDiscussions()
            ->map(fn (Discussion $d) => $this->documents->createActivityForDiscussion($d))
            ->all();

        return $this->ap([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->settings->base().'/federation/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => $this->discussions()->count(),
            'orderedItems' => $items,
        ]);
    }

    protected function discussions()
    {
        return Discussion::query()
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->whereHas('user', fn ($q) => $q->where('is_federated', false));
    }

    protected function publicDiscussions()
    {
        return $this->discussions()->with(['firstPost', 'user'])->latest()->limit(20)->get();
    }
}
