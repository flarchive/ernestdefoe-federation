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

    /** Followers delivered per DeliverActivity job (bounded memory + payload). */
    private const DELIVERY_CHUNK = 250;

    public function __construct(
        protected Settings $settings,
        protected DocumentBuilder $documents,
        protected LoggerInterface $log,
        protected Bus $bus,
        protected Fed $fed,
    ) {}

    /** Only public, visible, member-authored discussions federate. */
    public function shouldFederate(Discussion $discussion): bool
    {
        return $this->settings->enabled()
            && ! $discussion->is_private
            && $discussion->hidden_at === null
            && $discussion->user
            && ! $this->fed->isFederated($discussion->user);
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
            $this->deliverToFollowers(
                FederationFollower::query()->whereNull('user_id'),
                $this->documents->announceActivityForDiscussion($discussion),
                null
            );

            // The author's own followers → the per-member Create, signed by them.
            if ($author) {
                $this->deliverToFollowers(
                    FederationFollower::query()->where('user_id', $author->id),
                    $this->documents->createActivityForDiscussion($discussion),
                    $author->id
                );
            }
        } catch (\Throwable $e) {
            // A caught exception here means federation actually broke — warn so it
            // surfaces at the default production log level, not silent debug.
            $this->log->warning('[federation] announceDiscussion failed: '.$e->getMessage(), ['exception' => $e]);
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
            if ($this->fed->isFederated($post->user)) {
                return;
            }
            $author = $this->documents->authorOf($post->user);
            $activity = $this->documents->createActivityForReply($post, $discussion);

            // Remote participants already in this thread (bounded by thread size).
            $remoteInboxes = FederationUserData::whereIn('user_id', $discussion->posts()->pluck('user_id'))
                ->where('is_federated', true)->pluck('federated_inbox')->filter()->unique()->values()->all();

            // Thread participants → one bounded job; the author's followers →
            // chunked jobs (replies aren't boosted to community followers —
            // following the community gives you new topics, not every reply).
            if ($remoteInboxes) {
                $this->bus->dispatch(new Job\DeliverActivity($activity, $remoteInboxes, $author?->id));
            }
            if ($author) {
                $this->deliverToFollowers(
                    FederationFollower::query()->where('user_id', $author->id),
                    $activity, $author->id
                );
            }
        } catch (\Throwable $e) {
            $this->log->warning('[federation] announceReply failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Dispatch one DeliverActivity per chunk of followers, so a community with
     * tens of thousands of followers never materialises every inbox in memory or
     * serialises a giant array into one queue payload.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $followerQuery  scoped FederationFollower query
     */
    private function deliverToFollowers($followerQuery, array $activity, ?int $signerId): void
    {
        $followerQuery->chunkById(self::DELIVERY_CHUNK, function ($rows) use ($activity, $signerId) {
            $inboxes = $this->inboxesFor($rows);
            if ($inboxes) {
                $this->bus->dispatch(new Job\DeliverActivity($activity, $inboxes, $signerId));
            }
        });
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
