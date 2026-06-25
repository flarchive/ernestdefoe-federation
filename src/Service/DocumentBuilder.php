<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\Federation;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
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

    // ---- JSON-LD context + FEP-521a keys -----------------------------------

    /**
     * The shared JSON-LD @context. Beyond AS2 + the security vocab it defines
     * the Mastodon/FEP terms we emit (discoverable, manuallyApprovesFollowers,
     * sensitive, Hashtag, PropertyValue, and the FEP-521a Multikey /
     * assertionMethod) so strict JSON-LD consumers don't silently drop them.
     */
    public function context(): array
    {
        return [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            [
                'Hashtag' => 'as:Hashtag',
                'sensitive' => 'as:sensitive',
                'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                'toot' => 'http://joinmastodon.org/ns#',
                'discoverable' => 'toot:discoverable',
                'indexable' => 'toot:indexable',
                'featured' => ['@id' => 'toot:featured', '@type' => '@id'],
                'schema' => 'http://schema.org#',
                'PropertyValue' => 'schema:PropertyValue',
                'value' => 'schema:value',
                'Multikey' => 'https://w3id.org/security#Multikey',
                'assertionMethod' => ['@id' => 'https://w3id.org/security#assertionMethod', '@type' => '@id'],
                'publicKeyMultibase' => ['@id' => 'https://w3id.org/security#publicKeyMultibase', '@type' => 'https://w3id.org/security#multibase'],
            ],
        ];
    }

    /** FEP-521a — an assertionMethod Multikey published next to the legacy publicKey. */
    public function assertionMethod(string $ownerUrl, string $pem): array
    {
        $mb = $this->multibaseKey($pem);
        if ($mb === '') {
            return [];
        }

        return [[
            'id' => $ownerUrl.'#main-key-multikey',
            'type' => 'Multikey',
            'controller' => $ownerUrl,
            'publicKeyMultibase' => $mb,
        ]];
    }

    /** Encode an RSA public key (PEM) as a FEP-521a multibase Multikey value. */
    public function multibaseKey(string $pem): string
    {
        $pub = openssl_pkey_get_public($pem);
        $d = $pub ? openssl_pkey_get_details($pub) : false;
        if (! $d || ! isset($d['rsa']['n'], $d['rsa']['e'])) {
            return '';
        }
        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }  (PKCS#1, RFC 8017)
        $der = $this->derSeq($this->derInt($d['rsa']['n']).$this->derInt($d['rsa']['e']));
        // multicodec rsa-pub (0x1205) varint = 0x85 0x24, then multibase base58btc ('z').
        return 'z'.$this->base58("\x85\x24".$der);
    }

    private function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLen(strlen($bytes)).$bytes;
    }

    private function derSeq(string $body): string
    {
        return "\x30".$this->derLen(strlen($body)).$body;
    }

    private function derLen(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $out = '';
        while ($n > 0) {
            $out = chr($n & 0xff).$out;
            $n >>= 8;
        }

        return chr(0x80 | strlen($out)).$out;
    }

    /** Pure-PHP base58btc (no gmp/bcmath dependency). */
    private function base58(string $data): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $bytes = array_values(unpack('C*', $data) ?: []);
        $len = count($bytes);
        $zeros = 0;
        while ($zeros < $len && $bytes[$zeros] === 0) {
            $zeros++;
        }
        $out = '';
        $start = $zeros;
        while ($start < $len) {
            $rem = 0;
            for ($i = $start; $i < $len; $i++) {
                $acc = ($rem << 8) + $bytes[$i];
                $bytes[$i] = intdiv($acc, 58);
                $rem = $acc % 58;
            }
            $out = $alphabet[$rem].$out;
            while ($start < $len && $bytes[$start] === 0) {
                $start++;
            }
        }

        return str_repeat('1', $zeros).$out;
    }

    /** Mastodon-style profile metadata rows (schema:PropertyValue). */
    private function profileAttachment(string $label, string $href, string $text): array
    {
        return [[
            'type' => 'PropertyValue',
            'name' => $label,
            'value' => '<a href="'.e($href).'" rel="me" target="_blank">'.e($text).'</a>',
        ]];
    }

    /** Hashtags from a discussion's tags (flarum/tags), if that extension is on. */
    private function hashtagsFor(Discussion $discussion): array
    {
        $out = [];
        try {
            foreach ($discussion->tags as $t) {
                $slug = (string) ($t->slug ?? '');
                $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $slug);
                if ($name === '') {
                    continue;
                }
                $out[] = [
                    'type' => 'Hashtag',
                    'href' => $this->settings->base().'/t/'.rawurlencode($slug),
                    'name' => '#'.$name,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    // ---- Actors ------------------------------------------------------------

    /** The ActivityPub actor document for the community (a Service). */
    public function actor(): array
    {
        $base = $this->settings->base();
        $url = $this->settings->actorUrl();

        $doc = [
            '@context' => $this->context(),
            'id' => $url,
            // FEP-1b12: the community is a Group, so followers receive every
            // boosted discussion (Mastodon/Lemmy treat Group actors as communities).
            'type' => 'Group',
            'preferredUsername' => $this->settings->username(),
            'name' => (string) ($this->settings->raw('forum_title') ?: 'Community'),
            'summary' => (string) ($this->settings->raw('forum_description') ?: ''),
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'indexable' => true,
            'inbox' => $base.'/federation/inbox',
            'outbox' => $base.'/federation/outbox',
            'followers' => $base.'/federation/followers',
            'url' => $base.'/',
            'attachment' => $this->profileAttachment('Website', $base.'/', $this->settings->host()),
            'publicKey' => [
                'id' => $url.'#main-key',
                'owner' => $url,
                'publicKeyPem' => $this->keys->communityPublicKeyPem(),
            ],
            'assertionMethod' => $this->assertionMethod($url, $this->keys->communityPublicKeyPem()),
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

        $profileUrl = $base.'/u/'.$user->username;
        $doc = [
            '@context' => $this->context(),
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => $this->settings->userUsername($user),
            'name' => $this->settings->displayName($user),
            'summary' => $user->bio ? e(Str::limit(strip_tags((string) $user->bio), 400)) : '',
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'indexable' => true,
            'inbox' => $base.'/federation/users/'.$user->id.'/inbox',
            'outbox' => $base.'/federation/users/'.$user->id.'/outbox',
            'followers' => $base.'/federation/users/'.$user->id.'/followers',
            'url' => $profileUrl,
            'attachment' => $this->profileAttachment('Profile', $profileUrl, '@'.$this->settings->userUsername($user).'@'.$this->settings->host()),
            'publicKey' => [
                'id' => $url.'#main-key',
                'owner' => $url,
                'publicKeyPem' => $this->keys->userKeys($user)['public'],
            ],
            'assertionMethod' => $this->assertionMethod($url, $this->keys->userKeys($user)['public']),
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

        $note = [
            'id' => $this->noteId($discussion),
            'type' => 'Note',
            'attributedTo' => $actor,
            'content' => $content,
            'url' => $link,
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$followers],
        ];
        if ($hashtags = $this->hashtagsFor($discussion)) {
            $note['tag'] = $hashtags;
        }

        return $note;
    }

    public function createActivityForDiscussion(Discussion $discussion): array
    {
        $note = $this->noteForDiscussion($discussion);

        return [
            '@context' => $this->context(),
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
            '@context' => $this->context(),
            'id' => $noteId.'#announce',
            'type' => 'Announce',
            'actor' => $this->settings->actorUrl(),
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$this->settings->base().'/federation/followers'],
            'object' => $noteId,
        ];
    }

    /** A Create activity for a local reply (a Note inReplyTo the discussion). */
    public function createActivityForReply(Post $post, Discussion $discussion): array
    {
        $author = $this->authorOf($post->user);
        $actor = $author ? $this->settings->userActorUrl($author) : $this->settings->actorUrl();
        $followers = $author
            ? $this->settings->base().'/federation/users/'.$author->id.'/followers'
            : $this->settings->base().'/federation/followers';
        $link = $this->discussionUrl($discussion).'/'.$post->number;
        $published = ($post->created_at ?? \Carbon\Carbon::now())->toAtomString();

        $body = '';
        try {
            $body = $post->formatContent();
        } catch (\Throwable $e) {
            $body = e((string) $post->content);
        }
        $content = $body.'<p><a href="'.e($link).'">'.e($link).'</a></p>';
        $id = $this->noteId($discussion).'#post-'.$post->id;

        return [
            '@context' => $this->context(),
            'id' => $id,
            'type' => 'Create',
            'actor' => $actor,
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$followers],
            'object' => [
                'id' => $id,
                'type' => 'Note',
                'attributedTo' => $actor,
                'inReplyTo' => $this->noteId($discussion),
                'content' => $content,
                'url' => $link,
                'published' => $published,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => [$followers],
            ],
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
