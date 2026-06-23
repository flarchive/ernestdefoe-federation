<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\FederationFollower;
use ErnestDefoe\Federation\Job\DeliverActivity;
use ErnestDefoe\Federation\Service\ActorFetcher;
use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\RemoteUserSync;
use ErnestDefoe\Federation\Service\Settings;
use ErnestDefoe\Federation\Service\SignatureVerifier;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Inbox processing shared by the community and per-member inboxes. It verifies
 * the HTTP Signature, learns WHICH actor actually signed the request, and only
 * then dispatches by activity type. $target is the followed member, or null for
 * the community.
 *
 * Crucially, every handler is given the *verified* signing actor and checks it
 * against the actor claimed in the request body (and, for Delete, against the
 * post's original author) — a valid signature from server A must not be able to
 * act as actor B.
 */
trait HandlesInbox
{
    protected SignatureVerifier $verifier;

    protected ActorFetcher $fetcher;

    protected RemoteUserSync $remoteUsers;

    protected DocumentBuilder $documents;

    protected Events $events;

    public function __construct(
        Settings $settings,
        SignatureVerifier $verifier,
        ActorFetcher $fetcher,
        RemoteUserSync $remoteUsers,
        DocumentBuilder $documents,
        Events $events,
    ) {
        parent::__construct($settings);
        $this->verifier = $verifier;
        $this->fetcher = $fetcher;
        $this->remoteUsers = $remoteUsers;
        $this->documents = $documents;
        $this->events = $events;
    }

    protected function processInbox(ServerRequestInterface $request, ?User $target): ResponseInterface
    {
        $raw = (string) $request->getBody();

        $signedBy = $this->verifier->verify($request, $raw);
        if ($signedBy === null) {
            return new EmptyResponse(401);
        }

        $activity = json_decode($raw, true);
        if (! is_array($activity)) {
            return new EmptyResponse(400);
        }

        match ($activity['type'] ?? null) {
            'Follow' => $this->handleFollow($activity, $target, $signedBy),
            'Undo' => $this->handleUndo($activity, $target, $signedBy),
            'Create' => $this->handleCreate($activity, $signedBy),
            'Delete' => $this->handleDelete($activity, $signedBy),
            default => null, // accepted but ignored (Like, Announce, …)
        };

        return new EmptyResponse(202);
    }

    /**
     * True only when the actor claimed in the body is exactly the actor that
     * signed the request (case-insensitive, #fragment ignored).
     *
     * No host-only fallback: a signature from one account must NOT authorise
     * activities claiming to come from a DIFFERENT account on the same server —
     * otherwise any user on a shared host (mastodon.social, …) could forge
     * follows/replies as any other user there.
     */
    protected function actorAuthorized(string $signedBy, string $claimed): bool
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
        if (! $this->actorAuthorized($signedBy, $actorUri)) {
            return; // the signer is not the actor it claims to be
        }
        $remote = $this->fetcher->fetchActor($actorUri);
        if (! $remote || empty($remote['inbox'])) {
            return;
        }

        FederationFollower::query()->updateOrCreate(
            ['user_id' => $target?->id, 'actor' => $actorUri],
            [
                'inbox' => $remote['inbox'],
                'shared_inbox' => $remote['endpoints']['sharedInbox'] ?? null,
            ],
        );

        // Acknowledge the follow, signed by whichever actor was followed.
        $localActor = $target ? $this->settings->userActorUrl($target) : $this->settings->actorUrl();
        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActor.'#accept/'.bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $localActor,
            'object' => $activity,
        ];
        DeliverActivity::send($accept, [$remote['inbox']], $target?->id);
    }

    private function handleUndo(array $activity, ?User $target, string $signedBy): void
    {
        if (($activity['object']['type'] ?? null) === 'Follow') {
            $actor = (string) ($activity['actor'] ?? '');
            if (! $this->actorAuthorized($signedBy, $actor)) {
                return;
            }
            FederationFollower::where('user_id', $target?->id)->where('actor', $actor)->delete();
        }
    }

    /** A remote reply to one of our discussions → a federated post in it. */
    private function handleCreate(array $activity, string $signedBy): void
    {
        $claimed = (string) ($activity['actor'] ?? '');
        if (! $this->actorAuthorized($signedBy, $claimed)) {
            return; // attributed to an actor that did not sign the request
        }
        $obj = $activity['object'] ?? null;
        if (! is_array($obj)) {
            return;
        }
        $discussion = $this->documents->discussionFromUrl($obj['inReplyTo'] ?? null);
        if (! $discussion) {
            return; // not a reply to our content
        }
        $objectId = (string) ($obj['id'] ?? $activity['id'] ?? '');
        if ($objectId === '' || Post::where('federated_object', $objectId)->exists()) {
            return; // missing id or already imported
        }
        $author = $this->remoteUsers->upsert($claimed);
        if (! $author) {
            return;
        }
        $text = Federation::htmlToText((string) ($obj['content'] ?? ''));
        if ($text === '') {
            return;
        }
        // A malformed remote `published` must not 500 our inbox.
        try {
            $created = isset($obj['published']) ? Carbon::parse($obj['published']) : Carbon::now();
        } catch (\Throwable) {
            $created = Carbon::now();
        }

        $post = new CommentPost;
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->created_at = $created;
        $post->setContentAttribute($text, $author);
        $post->federated_object = $objectId;
        $post->save();

        $discussion->refreshCommentCount();
        $discussion->refreshLastPost();
        $discussion->save();

        // Let the rest of Flarum react to the federated reply (subscriptions,
        // search indexing, other Posted listeners). Wrapped so a misbehaving
        // listener can't turn a successful import into a 500. announceReply(),
        // also on Posted, no-ops here because the author is_federated.
        try {
            $this->events->dispatch(new Posted($post, $author));
        } catch (\Throwable $e) {
            // best-effort; the post is already saved
        }
    }

    /**
     * A remote Delete → remove the federated post it refers to, but ONLY when the
     * signature proves the request comes from that post's original author. Without
     * this check any server with one local follower could delete arbitrary posts.
     */
    private function handleDelete(array $activity, string $signedBy): void
    {
        $obj = $activity['object'] ?? null;
        $id = is_string($obj) ? $obj : (string) ($obj['id'] ?? '');
        if ($id === '') {
            return;
        }

        $post = Post::where('federated_object', $id)->first();
        if (! $post) {
            return;
        }
        $author = $post->user;
        if (! $author || ! $author->is_federated
            || ! $this->actorAuthorized($signedBy, (string) $author->federated_actor)) {
            return; // only the original author may delete their federated post
        }

        $discussion = $post->discussion;
        $post->delete();

        if ($discussion) {
            $discussion->refreshCommentCount();
            $discussion->refreshLastPost();
            $discussion->save();
        }
    }
}
