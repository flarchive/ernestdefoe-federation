<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\Application;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /nodeinfo/2.0 — server metadata for fediverse crawlers. */
class NodeInfoDataController extends AbstractFederationController
{
    public function __construct(
        Settings $settings,
        Fed $fed,
        protected Cache $cache,
    ) {
        parent::__construct($settings, $fed);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        // Crawlers poll NodeInfo frequently; the usage counts are full-table
        // aggregates, so cache them briefly — slight staleness is fine here.
        $usage = $this->cache->remember('federation:nodeinfo:usage', 300, fn () => [
            'users' => ['total' => User::whereDoesntHave('federationData', fn ($q) => $q->where('is_federated', true))->count()],
            'localPosts' => Post::count(),
            'localComments' => Discussion::count(),
        ]);

        return $this->ap([
            'version' => '2.0',
            'software' => ['name' => 'flarum', 'version' => Application::VERSION],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => $this->settings->raw('allow_sign_up') !== '0',
            'usage' => $usage,
            'metadata' => [
                'nodeName' => (string) ($this->settings->raw('forum_title') ?: 'Flarum'),
                'nodeDescription' => (string) ($this->settings->raw('forum_description') ?: ''),
            ],
        ], 'application/json');
    }
}
