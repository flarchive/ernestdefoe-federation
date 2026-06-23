<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Federation;
use ErnestDefoe\Federation\FederationFollower;
use ErnestDefoe\Federation\Job\DeliverActivity;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Inbox processing shared by the community and per-member inboxes. Verifies the
 * HTTP Signature, then dispatches by activity type. $target is the followed
 * member, or null for the community.
 */
trait HandlesInbox
{
    protected function processInbox(ServerRequestInterface $request, ?User $target): ResponseInterface
    {
        $raw = (string) $request->getBody();

        if (! Federation::verifyRequest($request, $raw)) {
            return new EmptyResponse(401);
        }

        $activity = json_decode($raw, true);
        if (! is_array($activity)) {
            return new EmptyResponse(400);
        }

        match ($activity['type'] ?? null) {
            'Follow' => $this->handleFollow($activity, $target),
            'Undo' => $this->handleUndo($activity, $target),
            'Create' => $this->handleCreate($activity),
            'Delete' => $this->handleDelete($activity),
            default => null, // accepted but ignored (Like, Announce, …)
        };

        return new EmptyResponse(202);
    }

    private function handleFollow(array $activity, ?User $target): void
    {
        $actorUri = (string) ($activity['actor'] ?? '');
        if ($actorUri === '') {
            return;
        }
        $remote = Federation::fetchActor($actorUri);
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
        $localActor = $target ? Federation::userActorUrl($target) : Federation::actorUrl();
        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActor.'#accept/'.bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $localActor,
            'object' => $activity,
        ];
        DeliverActivity::send($accept, [$remote['inbox']], $target?->id);
    }

    private function handleUndo(array $activity, ?User $target): void
    {
        if (($activity['object']['type'] ?? null) === 'Follow') {
            $actor = (string) ($activity['actor'] ?? '');
            if ($actor !== '') {
                FederationFollower::where('user_id', $target?->id)->where('actor', $actor)->delete();
            }
        }
    }

    /** A remote reply to one of our discussions → a federated post in it. */
    private function handleCreate(array $activity): void
    {
        $obj = $activity['object'] ?? null;
        if (! is_array($obj)) {
            return;
        }
        $discussion = Federation::discussionFromUrl($obj['inReplyTo'] ?? null);
        if (! $discussion) {
            return; // not a reply to our content
        }
        $objectId = (string) ($obj['id'] ?? $activity['id'] ?? '');
        if ($objectId === '' || Post::where('federated_object', $objectId)->exists()) {
            return; // missing id or already imported
        }
        $author = Federation::upsertRemoteUser((string) ($activity['actor'] ?? ''));
        if (! $author) {
            return;
        }
        $text = Federation::htmlToText((string) ($obj['content'] ?? ''));
        if ($text === '') {
            return;
        }
        $created = isset($obj['published']) ? Carbon::parse($obj['published']) : Carbon::now();

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
    }

    /** A remote Delete → remove the federated post it refers to. */
    private function handleDelete(array $activity): void
    {
        $obj = $activity['object'] ?? null;
        $id = is_string($obj) ? $obj : (string) ($obj['id'] ?? '');
        if ($id !== '') {
            Post::where('federated_object', $id)->delete();
        }
    }
}
