<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\Application;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /nodeinfo/2.0 — server metadata for fediverse crawlers. */
class NodeInfoDataController extends AbstractFederationController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard();

        $settings = resolve(SettingsRepositoryInterface::class);

        return $this->ap([
            'version' => '2.0',
            'software' => ['name' => 'flarum', 'version' => Application::VERSION],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => $settings->get('allow_sign_up') !== '0',
            'usage' => [
                'users' => ['total' => User::where('is_federated', false)->count()],
                'localPosts' => Post::count(),
                'localComments' => Discussion::count(),
            ],
            'metadata' => [
                'nodeName' => (string) ($settings->get('forum_title') ?: 'Flarum'),
                'nodeDescription' => (string) ($settings->get('forum_description') ?: ''),
            ],
        ], 'application/json');
    }
}
