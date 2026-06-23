<?php

/*
 * This file is part of ernestdefoe/federation.
 *
 * ActivityPub federation for Flarum 2. MIT licensed.
 */

use ErnestDefoe\Federation\Controller;
use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\Listener\AnnouncePost;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\User\User;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // ---- ActivityPub endpoints (forum route collection = site root) ---------
    // WebFinger + NodeInfo MUST live at the well-known root. Everything else is
    // namespaced under /federation/* so no core route is shadowed.
    (new Extend\Routes('forum'))
        ->get('/.well-known/webfinger', 'federation.webfinger', Controller\WebfingerController::class)
        ->get('/.well-known/nodeinfo', 'federation.nodeinfo', Controller\NodeInfoController::class)
        ->get('/nodeinfo/2.0', 'federation.nodeinfo.data', Controller\NodeInfoDataController::class)
        ->get('/federation/actor', 'federation.actor', Controller\ActorController::class)
        ->get('/federation/outbox', 'federation.outbox', Controller\OutboxController::class)
        ->get('/federation/followers', 'federation.followers', Controller\FollowersController::class)
        ->post('/federation/inbox', 'federation.inbox', Controller\InboxController::class)
        ->get('/federation/notes/{id}', 'federation.note', Controller\NoteController::class)
        ->get('/federation/users/{id}/actor', 'federation.user.actor', Controller\UserActorController::class)
        ->get('/federation/users/{id}/outbox', 'federation.user.outbox', Controller\UserOutboxController::class)
        ->get('/federation/users/{id}/followers', 'federation.user.followers', Controller\UserFollowersController::class)
        ->post('/federation/users/{id}/inbox', 'federation.user.inbox', Controller\UserInboxController::class),

    // Inbox POSTs come from remote servers with no CSRF token — exempt them.
    (new Extend\Csrf())
        ->exemptRoute('federation.inbox')
        ->exemptRoute('federation.user.inbox'),

    // ---- Push new discussions + replies to the fediverse (queued) -----------
    (new Extend\Event())
        ->listen(Posted::class, AnnouncePost::class),

    // ---- Forum payload: the community handle + per-user handle ---------------
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('federationEnabled')
                ->get(fn () => resolve(Settings::class)->enabled()),
            Schema\Str::make('federationHandle')
                ->nullable()
                ->get(function () {
                    $settings = resolve(Settings::class);

                    return $settings->enabled() ? $settings->handle() : null;
                }),
        ]),

    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Str::make('federationHandle')
                ->nullable()
                ->get(function (User $user) {
                    $settings = resolve(Settings::class);

                    return ($settings->enabled() && ! $user->is_federated)
                        ? $settings->previewUserHandle($user)
                        : null;
                }),
        ]),

    // ---- Settings consumed by the admin UI ----------------------------------
    (new Extend\Settings())
        ->serializeToForum('federationEnabled', Federation::PREFIX . 'enabled', fn ($v) => (bool) $v),
];
