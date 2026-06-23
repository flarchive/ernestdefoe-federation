<?php

namespace ErnestDefoe\Federation\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Application;
use Flarum\Post\Post;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /nodeinfo/2.0 — server metadata for fediverse crawlers. */
class NodeInfoDataController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        return $this->ap([
            'version' => '2.0',
            'software' => ['name' => 'flarum', 'version' => Application::VERSION],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => $this->settings->raw('allow_sign_up') !== '0',
            'usage' => [
                'users' => ['total' => User::whereDoesntHave('federationData', fn ($q) => $q->where('is_federated', true))->count()],
                'localPosts' => Post::count(),
                'localComments' => Discussion::count(),
            ],
            'metadata' => [
                'nodeName' => (string) ($this->settings->raw('forum_title') ?: 'Flarum'),
                'nodeDescription' => (string) ($this->settings->raw('forum_description') ?: ''),
            ],
        ], 'application/json');
    }
}
