<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\FederationFollower;
use ErnestDefoe\Federation\Job\DeliverActivity;
use ErnestDefoe\Federation\PostFederationMeta;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Saving;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Carbon;

/**
 * Processes one inbound ActivityPub activity: verifies the HTTP Signature, learns
 * WHICH actor signed it, then dispatches by type. Runs inside a queued job so a
 * slow remote (the actor fetch is a network call) never blocks the web worker.
 *
 * Every handler checks the verified signer against the actor claimed in the body
 * (and, for Delete, against the post's author) so a signature from one account
 * can't act as another. Remote-supplied strings are length-guarded before any DB
 * write so an over-long URI can't turn into an uncaught QueryException.
 */
class InboxProcessor
{
    private const MAX_URI = 500;

    /** Inbound replies accepted per remote host per window. */
    private const REPLIES_PER_HOST = 30;

    private const WINDOW = 600; // seconds

    public function __construct(
        protected SignatureVerifier $verifier,
        protected ActorFetcher $fetcher,
        protected RemoteUserSync $remoteUsers,
        protected DocumentBuilder $documents,
        protected Settings $settings,
        protected Events $events,
        protected Bus $bus,
        protected Fed $fed,
        protected Cache $cache,
    ) {}

    /** Count one hit against $key; false once the window's limit is passed. */
    private function withinLimit(string $key, int $limit): bool
    {
        $bucket = 'federation:limit:'.$key.':'.intdiv(time(), self::WINDOW);
        $this->cache->add($bucket, 0, self::WINDOW);

        return $this->cache->increment($bucket) <= $limit;
    }

    /** @param array<string,string> $headers lower-cased header name => value */
    public function process(string $method, string $requestTarget, array $headers, string $rawBody, ?int $targetUserId, int $receivedAt): void
    {
        $signedBy = $this->verifier->verifyParts($method, $requestTarget, $headers, $rawBody, $receivedAt);
        if ($signedBy === null) {
            return; // bad/replayed signature — silently dropped (already 202'd)
        }

        $activity = json_decode($rawBody, true);
        if (! is_array($activity)) {
            return;
        }

        $target = $targetUserId ? User::find($targetUserId) : null;
        if ($targetUserId && ! $target) {
            return; // addressed member is gone
        }

        match ($activity['type'] ?? null) {
            'Follow' => $this->handleFollow($activity, $target, $signedBy),
            'Undo' => $this->handleUndo($activity, $target, $signedBy),
            'Create' => $this->handleCreate($activity, $signedBy),
            'Delete' => $this->handleDelete($activity, $signedBy),
            default => null, // accepted but ignored (Like, Announce, …)
        };
    }

    /**
     * True only when the actor claimed in the body is exactly the actor that
     * signed the request (case-insensitive, #fragment ignored). No host-only
     * fallback: a signature from one account must not authorise activities
     * claiming to come from a different account on the same server.
     */
    private function actorAuthorized(string $signedBy, string $claimed): bool
    {
        if ($claimed === '') {
            return false;
        }
        $a = strtok($signedBy, '#') ?: $signedBy;
        $b = strtok($claimed, '#') ?: $claimed;

        return strcasecmp($a, $b) === 0;
    }

    private function handleFollow(array $activity, ?User $target, string $signedBy): void
    {
        $actorUri = (string) ($activity['actor'] ?? '');
        if (strlen($actorUri) > self::MAX_URI || ! $this->actorAuthorized($signedBy, $actorUri)) {
            return;
        }
        $remote = $this->fetcher->fetchActor($actorUri);
        if (! $remote || empty($remote['inbox'])) {
            return;
        }
        $inbox = (string) $remote['inbox'];
        if (strlen($inbox) > self::MAX_URI) {
            return;
        }
        $shared = $remote['endpoints']['sharedInbox'] ?? null;
        if ($shared && strlen((string) $shared) > self::MAX_URI) {
            $shared = null;
        }

        FederationFollower::query()->updateOrCreate(
            ['user_id' => $target?->id, 'actor' => $actorUri],
            ['inbox' => $inbox, 'shared_inbox' => $shared],
        );

        // Acknowledge the follow, signed by whichever actor was followed.
        $localActor = $target ? $this->settings->userActorUrl($target) : $this->settings->actorUrl();
        $this->deliver([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActor.'#accept/'.bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $localActor,
            'object' => $activity,
        ], [$inbox], $target?->id);
    }

    private function handleUndo(array $activity, ?User $target, string $signedBy): void
    {
        if (($activity['object']['type'] ?? null) !== 'Follow') {
            return;
        }
        $actor = (string) ($activity['actor'] ?? '');
        if (strlen($actor) > self::MAX_URI || ! $this->actorAuthorized($signedBy, $actor)) {
            return;
        }
        FederationFollower::where('user_id', $target?->id)->where('actor', $actor)->delete();
    }

    /** A remote reply to one of our discussions → a federated post in it. */
    private function handleCreate(array $activity, string $signedBy): void
    {
        $claimed = (string) ($activity['actor'] ?? '');
        if (strlen($claimed) > self::MAX_URI || ! $this->actorAuthorized($signedBy, $claimed)) {
            return;
        }
        $obj = $activity['object'] ?? null;
        if (! is_array($obj)) {
            return;
        }
        $discussion = $this->documents->discussionFromUrl($obj['inReplyTo'] ?? null);
        // Gate exactly like outbound federation: only discussions a guest can
        // read (never private, hidden, restricted-tag or unapproved ones just
        // because a remote guessed an id), and never a locked one.
        if (! $discussion
            || ! $this->settings->enabled()
            || $discussion->is_private
            || $discussion->hidden_at !== null
            || $discussion->is_locked
            || ! Discussion::query()->whereVisibleTo(new Guest)->whereKey($discussion->id)->exists()) {
            return;
        }
        $objectId = (string) ($obj['id'] ?? $activity['id'] ?? '');
        if ($objectId === '' || strlen($objectId) > self::MAX_URI) {
            return;
        }
        if (PostFederationMeta::where('federated_object', $objectId)->exists()) {
            return; // already imported
        }
        // Flood guard: a remote server gets a bounded number of replies per
        // window, however many accounts it signs as.
        if (! $this->withinLimit('replies:'.strtolower((string) parse_url($claimed, PHP_URL_HOST)), self::REPLIES_PER_HOST)) {
            return;
        }
        $author = $this->remoteUsers->upsert($claimed);
        // The mirror account is an ordinary member to Flarum, so the forum's
        // own reply permission decides (tag permissions, flarum/lock, a
        // moderator suspending the mirror, …).
        if (! $author || $author->cannot('reply', $discussion)) {
            return;
        }
        $text = Federation::htmlToText((string) ($obj['content'] ?? ''));
        if ($text === '') {
            return;
        }
        try {
            $created = isset($obj['published']) ? Carbon::parse($obj['published']) : Carbon::now();
        } catch (\Throwable) {
            $created = Carbon::now(); // a malformed remote `published` must not blow up
        }

        $post = new CommentPost;
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->created_at = $created;
        $post->setContentAttribute($text, $author);
        $post->setRelation('discussion', $discussion);
        // Let moderation extensions see the post as if it were posted through
        // the API: flarum/approval holds it for approval when the mirror may not
        // reply without approval, word filters run, and so on.
        try {
            $this->events->dispatch(new Saving($post, $author, ['attributes' => ['content' => $text]]));
        } catch (\Throwable) {
            return; // a moderation listener refused it
        }
        $post->save();
        PostFederationMeta::create(['post_id' => $post->id, 'federated_object' => $objectId]);

        $discussion->refreshCommentCount();
        $discussion->refreshLastPost();
        $discussion->save();

        // Let the rest of Flarum react (subscriptions, search, other listeners).
        // Guarded so a misbehaving listener can't fail the import; announceReply
        // (also on Posted) no-ops because the author is_federated.
        try {
            $this->events->dispatch(new Posted($post, $author));
        } catch (\Throwable $e) {
            // best-effort; the post is already saved
        }
    }

    /** A remote Delete → remove the post, only if the signer authored it. */
    private function handleDelete(array $activity, string $signedBy): void
    {
        $obj = $activity['object'] ?? null;
        $id = is_string($obj) ? $obj : (string) ($obj['id'] ?? '');
        if ($id === '') {
            return;
        }
        $post = PostFederationMeta::where('federated_object', $id)->first()?->post;
        if (! $post) {
            return;
        }
        $author = $post->user;
        if (! $this->fed->isFederated($author)
            || ! $this->actorAuthorized($signedBy, (string) $this->fed->federatedActor($author))) {
            return; // only the original author may delete their federated post
        }

        $discussion = $post->discussion;
        $post->delete(); // cascade removes the companion meta row

        if ($discussion) {
            $discussion->refreshCommentCount();
            $discussion->refreshLastPost();
            $discussion->save();
        }
    }

    /** @param string[] $inboxes */
    private function deliver(array $activity, array $inboxes, ?int $signerId): void
    {
        $inboxes = array_values(array_filter($inboxes));
        if ($inboxes) {
            $this->bus->dispatch(new DeliverActivity($activity, $inboxes, $signerId));
        }
    }
}
