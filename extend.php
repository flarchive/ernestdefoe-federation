<?php

/*
 * This file is part of ernestdefoe/federation.
 *
 * ActivityPub federation for Flarum 2. MIT licensed.
 */

use ErnestDefoe\Federation\Api\ForumFederationFields;
use ErnestDefoe\Federation\Api\UserFederationFields;
use ErnestDefoe\Federation\Controller;
use ErnestDefoe\Federation\Listener\AnnouncePost;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;

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
    // Invokable field classes (not closures) so Settings is constructor-injected.
    // `federationEnabled` is exposed ONLY here — registering it again via
    // Extend\Settings()->serializeToForum() would double-write the same JSON:API
    // attribute, so that registration was removed.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumFederationFields::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserFederationFields::class),
];
