<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Federation;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * Builds the ActivityPub JSON documents: actor (community + member), WebFinger,
 * Notes and the Create/Announce activities for discussions. Pure construction —
 * it reads identity from {@see Settings} and public keys from {@see KeyManager}.
 */
class DocumentBuilder
{
    public function __construct(
        protected Settings $settings,
        protected KeyManager $keys,
        protected Fed $fed,
    ) {}

    /** The author who federates a discussion/post, or null = the community. */
    public function authorOf(?User $user): ?User
    {
        return ($user && ! $this->fed->isFederated($user)) ? $user : null;
    }

    // ---- Actors ------------------------------------------------------------

    /** The ActivityPub actor document for the community (a Service). */
    public function actor(): array
    {
        $base = $this->settings->base();
        $url = $this->settings->actorUrl();

        $doc = [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $url,
            'type' => 'Service',
            'preferredUsername' => $this->settings->username(),
            'name' => (string) ($this->settings->raw('forum_title') ?: 'Community'),
            'summary' => (string) ($this->settings->raw('forum_description') ?: ''),
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'inbox' => $base.'/federation/inbox',
            'outbox' => $base.'/federation/outbox',
            'followers' => $base.'/federation/followers',
            'url' => $base.'/',
            'publicKey' => [
                'id' => $url.'#main-key',
                'owner' => $url,
                'publicKeyPem' => $this->keys->communityPublicKeyPem(),
            ],
        ];

        if ($icon = $this->settings->iconUrl()) {
            $doc['icon'] = ['type' => 'Image', 'url' => $icon];
        }

        return $doc;
    }

    /** A member's ActivityPub actor document (a Person). */
    public function userActor(User $user): array
    {
        $base = $this->settings->base();
        $url = $this->settings->userActorUrl($user);

        $doc = [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => $this->settings->userUsername($user),
            'name' => $this->settings->displayName($user),
            'summary' => $user->bio ? e(Str::limit(strip_tags((string) $user->bio), 400)) : '',
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'inbox' => $base.'/federation/users/'.$user->id.'/inbox',
            'outbox' => $base.'/federation/users/'.$user->id.'/outbox',
            'followers' => $base.'/federation/users/'.$user->id.'/followers',
            'url' => $base.'/u/'.$user->username,
            'publicKey' => [
                'id' => $url.'#main-key',
                'owner' => $url,
                'publicKeyPem' => $this->keys->userKeys($user)['public'],
            ],
        ];

        if ($user->avatar_url) {
            $doc['icon'] = ['type' => 'Image', 'url' => (string) $user->avatar_url];
        }

        return $doc;
    }

    // ---- WebFinger ---------------------------------------------------------

    public function webfinger(): array
    {
        return [
            'subject' => 'acct:'.$this->settings->username().'@'.$this->settings->host(),
            'aliases' => [$this->settings->actorUrl()],
            'links' => [
                ['rel' => 'self', 'type' => Federation::CTYPE, 'href' => $this->settings->actorUrl()],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => $this->settings->base().'/'],
            ],
        ];
    }

    public function userWebfinger(User $user): array
    {
        $url = $this->settings->userActorUrl($user);

        return [
            'subject' => 'acct:'.$this->settings->userUsername($user).'@'.$this->settings->host(),
            'aliases' => [$url, $this->settings->base().'/u/'.$user->username],
            'links' => [
                ['rel' => 'self', 'type' => Federation::CTYPE, 'href' => $url],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => $this->settings->base().'/u/'.$user->username],
            ],
        ];
    }

    // ---- Notes / activities for a discussion -------------------------------

    public function discussionUrl(Discussion $discussion): string
    {
        return $this->settings->base().'/d/'.$discussion->id;
    }

    public function noteId(Discussion $discussion): string
    {
        return $this->settings->base().'/federation/notes/'.$discussion->id;
    }

    /** The standalone Note object for a discussion (also embedded in Create). */
    public function noteForDiscussion(Discussion $discussion): array
    {
        $author = $this->authorOf($discussion->user);
        $actor = $author ? $this->settings->userActorUrl($author) : $this->settings->actorUrl();
        $followers = $author
            ? $this->settings->base().'/federation/users/'.$author->id.'/followers'
            : $this->settings->base().'/federation/followers';
        $published = ($discussion->created_at ?? \Carbon\Carbon::now())->toAtomString();

        $first = $discussion->firstPost;
        $excerpt = '';
        if ($first instanceof CommentPost) {
            try {
                $excerpt = trim(Str::limit(strip_tags($first->formatContent()), 300));
            } catch (\Throwable $e) {
                $excerpt = '';
            }
        }
        $link = $this->discussionUrl($discussion);
        $content = '<p><strong>'.e($discussion->title).'</strong></p>'
            .($excerpt !== '' ? '<p>'.e($excerpt).'</p>' : '')
            .'<p><a href="'.e($link).'">'.e($link).'</a></p>';

        return [
            'id' => $this->noteId($discussion),
            'type' => 'Note',
            'attributedTo' => $actor,
            'content' => $content,
            'url' => $link,
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$followers],
        ];
    }

    public function createActivityForDiscussion(Discussion $discussion): array
    {
        $note = $this->noteForDiscussion($discussion);

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $note['id'].'#create',
            'type' => 'Create',
            'actor' => $note['attributedTo'],
            'published' => $note['published'],
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note,
        ];
    }

    /**
     * The community boosts (Announce) a discussion's Note to its followers, so
     * anyone following @{community} sees every new discussion with the member's
     * native author attribution preserved (the remote dereferences the Note).
     */
    public function announceActivityForDiscussion(Discussion $discussion): array
    {
        $noteId = $this->noteId($discussion);
        $published = ($discussion->created_at ?? \Carbon\Carbon::now())->toAtomString();

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteId.'#announce',
            'type' => 'Announce',
            'actor' => $this->settings->actorUrl(),
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$this->settings->base().'/federation/followers'],
            'object' => $noteId,
        ];
    }

    /** Resolve one of our Note URLs (or the human /d/ link) back to a Discussion. */
    public function discussionFromUrl(?string $url): ?Discussion
    {
        if (! $url) {
            return null;
        }
        $base = $this->settings->base();
        foreach ([$base.'/federation/notes/', $base.'/d/'] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                $rest = trim(substr($url, strlen($prefix)), '/');
                $id = (int) strtok($rest, '-#?');

                return $id > 0 ? Discussion::find($id) : null;
            }
        }

        return null;
    }
}
