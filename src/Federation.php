<?php

namespace ErnestDefoe\Federation;

use ErnestDefoe\Federation\Service\DocumentBuilder;
use ErnestDefoe\Federation\Service\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Psr\Log\LoggerInterface;

/**
 * ActivityPub for Flarum 2 — makes the community a single discoverable,
 * followable actor (a Service), and every member a Person actor. Fediverse
 * users (Mastodon, Lemmy, …) can follow @{community}@{host} or @{member}@{host}
 * and receive each new discussion in their timeline; the community boosts
 * (Announce) member discussions so following the community surfaces everything.
 *
 * This is a thin coordinator: it decides WHAT to federate and to WHOM, then hands
 * delivery to a queued job. The heavy lifting lives in injectable services under
 * {@see \ErnestDefoe\Federation\Service} — settings/identity, key management,
 * document building, HTTP signing/verification, outbound fetch/delivery and
 * remote-user sync — so each concern is testable in isolation.
 *
 * Everything lives under /federation/* and /.well-known/* — no core files,
 * routes or models are modified, so it survives core updates untouched.
 */
class Federation
{
    public const CTYPE = 'application/activity+json';

    public const PREFIX = Settings::PREFIX;

    public function __construct(
        protected Settings $settings,
        protected DocumentBuilder $documents,
        protected LoggerInterface $log,
        protected Bus $bus,
    ) {}

    /** Only public, visible, member-authored discussions federate. */
    public function shouldFederate(Discussion $discussion): bool
    {
        return $this->settings->enabled()
            && ! $discussion->is_private
            && $discussion->hidden_at === null
            && $discussion->user
            && ! Fed::isFederated($discussion->user);
    }

    /** Announce a brand-new discussion: community boost + author's own followers. */
    public function announceDiscussion(Discussion $discussion): void
    {
        try {
            if (! $this->shouldFederate($discussion)) {
                return;
            }
            $author = $this->documents->authorOf($discussion->user);

            // Community followers (user_id null) → a boost from the community
            // actor (the actor they follow → lands in their home timeline).
            $communityInboxes = $this->inboxesFor(
                FederationFollower::whereNull('user_id')->get()
            );
            if ($communityInboxes) {
                $this->bus->dispatch(new Job\DeliverActivity(
                    $this->documents->announceActivityForDiscussion($discussion), $communityInboxes, null
                ));
            }

            // The author's own followers → the per-member Create, signed by them.
            if ($author) {
                $authorInboxes = $this->inboxesFor(
                    FederationFollower::where('user_id', $author->id)->get()
                );
                if ($authorInboxes) {
                    $this->bus->dispatch(new Job\DeliverActivity(
                        $this->documents->createActivityForDiscussion($discussion), $authorInboxes, $author->id
                    ));
                }
            }
        } catch (\Throwable $e) {
            $this->log->debug('[federation] announceDiscussion skipped: '.$e->getMessage());
        }
    }

    /** Cross-post a local reply to followers + remote thread participants. */
    public function announceReply(Post $post, Discussion $discussion): void
    {
        try {
            if (! ($post instanceof CommentPost) || ! $this->shouldFederate($discussion)) {
                return;
            }
            // Never re-broadcast a reply that arrived FROM the fediverse.
            if (Fed::isFederated($post->user)) {
                return;
            }
            $author = $this->documents->authorOf($post->user);
            $actor = $author ? $this->settings->userActorUrl($author) : $this->settings->actorUrl();
            $followers = $author
                ? $this->settings->base().'/federation/users/'.$author->id.'/followers'
                : $this->settings->base().'/federation/followers';
            $link = $this->documents->discussionUrl($discussion).'/'.$post->number;

            // Remote participants already in this thread.
            $remoteInboxes = FederationUserData::whereIn('user_id', $discussion->posts()->pluck('user_id'))
                ->where('is_federated', true)->pluck('federated_inbox')->filter()->all();
            // The author's followers (replies aren't boosted to community followers
            // to avoid flooding — following the community gives you new topics).
            $followerInboxes = $author
                ? $this->inboxesFor(FederationFollower::where('user_id', $author->id)->get())
                : [];
            $inboxes = array_values(array_unique(array_merge($followerInboxes, $remoteInboxes)));
            if (! $inboxes) {
                return;
            }

            $published = ($post->created_at ?? \Carbon\Carbon::now())->toAtomString();
            $body = '';
            try {
                $body = $post->formatContent();
            } catch (\Throwable $e) {
                $body = e((string) $post->content);
            }
            $content = $body.'<p><a href="'.e($link).'">'.e($link).'</a></p>';
            $noteId = $this->documents->noteId($discussion);

            $activity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $noteId.'#post-'.$post->id,
                'type' => 'Create',
                'actor' => $actor,
                'published' => $published,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => [$followers],
                'object' => [
                    'id' => $noteId.'#post-'.$post->id,
                    'type' => 'Note',
                    'attributedTo' => $actor,
                    'inReplyTo' => $noteId,
                    'content' => $content,
                    'url' => $link,
                    'published' => $published,
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$followers],
                ],
            ];
            $this->bus->dispatch(new Job\DeliverActivity($activity, $inboxes, $author?->id));
        } catch (\Throwable $e) {
            $this->log->debug('[federation] announceReply skipped: '.$e->getMessage());
        }
    }

    /** @param \Illuminate\Support\Collection<FederationFollower> $rows */
    public function inboxesFor($rows): array
    {
        return $rows->map(fn (FederationFollower $f) => $f->deliveryInbox())
            ->filter()->unique()->values()->all();
    }

    /** Flatten remote HTML content into plain text Flarum's formatter can parse. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>#i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
