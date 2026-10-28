<?php

namespace ErnestDefoe\Federation\Listener;

use ErnestDefoe\Federation\Federation;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;

/**
 * When a member publishes content, push it to the fediverse:
 *  - the first post of a discussion → the community boosts (Announce) it to
 *    its followers (and the author's own followers get a Create);
 *  - a later reply → cross-posted to the author's followers + remote
 *    participants already in the thread.
 *
 * Delivery is queued; everything is a no-op unless federation is enabled and the
 * discussion is readable by guests and member-authored (see Federation::shouldFederate).
 * A post held for approval federates when it is approved (PostWasApproved).
 */
class AnnouncePost
{
    public function __construct(
        protected Federation $federation,
    ) {
    }

    /** @param Posted|\Flarum\Approval\Event\PostWasApproved $event */
    public function handle(object $event): void
    {
        $post = $event->post ?? null;
        if (! ($post instanceof CommentPost)) {
            return;
        }
        $discussion = $post->discussion;
        if (! $discussion) {
            return;
        }

        if ((int) $post->number <= 1) {
            // Ensure the relation the Note builder reads is loaded.
            $discussion->setRelation('firstPost', $post);
            $this->federation->announceDiscussion($discussion);
        } else {
            $this->federation->announceReply($post, $discussion);
        }
    }
}
