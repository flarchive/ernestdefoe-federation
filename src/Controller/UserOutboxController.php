<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /federation/users/{id}/outbox — that member's recent discussions (paged). */
class UserOutboxController extends AbstractFederationController
{
    private const PER_PAGE = 20;

    public function __construct(
        Settings $settings,
        Fed $fed,
        protected DocumentBuilder $documents,
    ) {
        parent::__construct($settings, $fed);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();
        $user = $this->localMember($request);

        $id = $this->settings->base().'/federation/users/'.$user->id.'/outbox';
        $page = (int) ($request->getQueryParams()['page'] ?? 0);
        $base = Discussion::query()
            ->where('user_id', $user->id)
            ->where('is_private', false)
            ->whereNull('hidden_at');
        $total = (clone $base)->count();

        if ($page < 1) {
            return $this->ap([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $id,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $id.'?page=1',
            ]);
        }

        $items = (clone $base)
            ->with(['firstPost', 'user', 'user.federationData'])
            ->latest()
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (Discussion $d) => $this->documents->createActivityForDiscussion($d))
            ->all();

        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id.'?page='.$page,
            'type' => 'OrderedCollectionPage',
            'partOf' => $id,
            'totalItems' => $total,
            'orderedItems' => $items,
        ];
        if ($page * self::PER_PAGE < $total) {
            $doc['next'] = $id.'?page='.($page + 1);
        }
        if ($page > 1) {
            $doc['prev'] = $id.'?page='.($page - 1);
        }

        return $this->ap($doc);
    }
}
