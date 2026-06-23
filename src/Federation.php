<?php

namespace ErnestDefoe\Federation;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\Config;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * ActivityPub for Flarum 2 — makes the community a single discoverable,
 * followable actor (a Service), and every member a Person actor. Fediverse
 * users (Mastodon, Lemmy, …) can follow @{community}@{host} or @{member}@{host}
 * and receive each new discussion in their timeline; the community boosts
 * (Announce) member discussions so following the community surfaces everything.
 *
 * Implements WebFinger discovery, actor/outbox/inbox documents, signed delivery
 * (HTTP Signatures, draft-cavage as Mastodon uses), inbound Follow/Undo, and
 * inbound replies/likes. Off by default (an admin enables it).
 *
 * Everything lives under /federation/* and /.well-known/* — no core files,
 * routes or models are modified, so it survives core updates untouched.
 */
class Federation
{
    public const CTYPE = 'application/activity+json';

    public const PREFIX = 'ernestdefoe-federation.';

    protected static function settings(): SettingsRepositoryInterface
    {
        return resolve(SettingsRepositoryInterface::class);
    }

    public static function get(string $key, $default = null)
    {
        return self::settings()->get(self::PREFIX.$key, $default);
    }

    public static function enabled(): bool
    {
        return (bool) self::get('enabled', false);
    }

    public static function base(): string
    {
        return rtrim((string) resolve(Config::class)->url(), '/');
    }

    public static function host(): string
    {
        return (string) (parse_url(self::base(), PHP_URL_HOST) ?: 'localhost');
    }

    public static function username(): string
    {
        $u = (string) self::get('username', '');
        if ($u !== '') {
            return $u;
        }
        $title = (string) self::settings()->get('forum_title', 'community');

        return Str::slug($title, '_') ?: 'community';
    }

    public static function actorUrl(): string
    {
        return self::base().'/federation/actor';
    }

    public static function handle(): string
    {
        return '@'.self::username().'@'.self::host();
    }

    // ---- Community keypair (stored in settings; generated on first use) ----

    /** @return array{public:string,private:string} PEM strings */
    public static function keys(): array
    {
        $pub = (string) self::get('public_key', '');
        $priv = (string) self::get('private_key', '');
        if ($pub !== '' && $priv !== '') {
            return ['public' => $pub, 'private' => $priv];
        }
        [$pub, $priv] = self::generateKeypair();
        self::settings()->set(self::PREFIX.'public_key', $pub);
        self::settings()->set(self::PREFIX.'private_key', $priv);

        return ['public' => $pub, 'private' => $priv];
    }

    public static function publicKeyPem(): string
    {
        return self::keys()['public'];
    }

    /** @return array{0:string,1:string} [publicPem, privatePem] */
    public static function generateKeypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            throw new \RuntimeException('Could not generate a federation keypair (OpenSSL).');
        }
        openssl_pkey_export($res, $priv);
        $pub = (string) openssl_pkey_get_details($res)['key'];

        return [$pub, $priv];
    }

    // ---- Per-member (Person) actors ----

    public static function displayName(User $user): string
    {
        return (string) ($user->display_name ?: $user->username);
    }

    /** A member's unique fediverse username (lazy: slug of name, deduped by id). */
    public static function userUsername(User $user): string
    {
        if ($user->ap_username) {
            return $user->ap_username;
        }
        $base = Str::slug(self::displayName($user), '_') ?: 'member';
        $candidate = $base;
        if (strcasecmp($candidate, self::username()) === 0
            || User::where('ap_username', $candidate)->exists()) {
            $candidate = $base.$user->id;
        }
        $user->forceFill(['ap_username' => $candidate])->save();

        return $candidate;
    }

    /** @return array{public:string,private:string} a member's own keypair (lazy) */
    public static function userKeys(User $user): array
    {
        if ($user->ap_public_key && $user->ap_private_key) {
            return ['public' => $user->ap_public_key, 'private' => $user->ap_private_key];
        }
        [$pub, $priv] = self::generateKeypair();
        $user->forceFill(['ap_public_key' => $pub, 'ap_private_key' => $priv])->save();

        return ['public' => $pub, 'private' => $priv];
    }

    public static function userActorUrl(User $user): string
    {
        return self::base().'/federation/users/'.$user->id.'/actor';
    }

    public static function userHandle(User $user): string
    {
        return '@'.self::userUsername($user).'@'.self::host();
    }

    /** The member's username WITHOUT persisting (safe to call during serialization). */
    public static function previewUserUsername(User $user): string
    {
        if ($user->ap_username) {
            return $user->ap_username;
        }

        return Str::slug(self::displayName($user), '_') ?: 'member';
    }

    public static function previewUserHandle(User $user): string
    {
        return '@'.self::previewUserUsername($user).'@'.self::host();
    }

    /** The author who federates a discussion/post, or null = the community. */
    public static function authorOf(?User $user): ?User
    {
        return ($user && ! $user->is_federated) ? $user : null;
    }

    // ---- Documents ----

    /** The ActivityPub actor document for the community (a Service). */
    public static function actor(): array
    {
        $base = self::base();
        $url = self::actorUrl();

        $doc = [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $url,
            'type' => 'Service',
            'preferredUsername' => self::username(),
            'name' => (string) (self::settings()->get('forum_title') ?: 'Community'),
            'summary' => (string) (self::settings()->get('forum_description') ?: ''),
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'inbox' => $base.'/federation/inbox',
            'outbox' => $base.'/federation/outbox',
            'followers' => $base.'/federation/followers',
            'url' => $base.'/',
            'publicKey' => [
                'id' => $url.'#main-key',
                'owner' => $url,
                'publicKeyPem' => self::publicKeyPem(),
            ],
        ];

        if ($icon = self::iconUrl()) {
            $doc['icon'] = ['type' => 'Image', 'url' => $icon];
        }

        return $doc;
    }

    protected static function iconUrl(): ?string
    {
        $logo = self::settings()->get('logo_path') ?: self::settings()->get('favicon_path');

        return $logo ? self::base().'/assets/'.$logo : null;
    }

    /** A member's ActivityPub actor document (a Person). */
    public static function userActor(User $user): array
    {
        $base = self::base();
        $url = self::userActorUrl($user);

        $doc = [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => self::userUsername($user),
            'name' => self::displayName($user),
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
                'publicKeyPem' => self::userKeys($user)['public'],
            ],
        ];

        if ($user->avatar_url) {
            $doc['icon'] = ['type' => 'Image', 'url' => (string) $user->avatar_url];
        }

        return $doc;
    }

    public static function webfinger(): array
    {
        return [
            'subject' => 'acct:'.self::username().'@'.self::host(),
            'aliases' => [self::actorUrl()],
            'links' => [
                ['rel' => 'self', 'type' => self::CTYPE, 'href' => self::actorUrl()],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => self::base().'/'],
            ],
        ];
    }

    public static function userWebfinger(User $user): array
    {
        $url = self::userActorUrl($user);

        return [
            'subject' => 'acct:'.self::userUsername($user).'@'.self::host(),
            'aliases' => [$url, self::base().'/u/'.$user->username],
            'links' => [
                ['rel' => 'self', 'type' => self::CTYPE, 'href' => $url],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => self::base().'/u/'.$user->username],
            ],
        ];
    }

    /** Resolve a webfinger username to the community OR a local member. */
    public static function resolveUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        if ($username === strtolower(self::username())) {
            return ['type' => 'community'];
        }
        $user = User::where('ap_username', $username)->where('is_federated', false)->first();

        return $user ? ['type' => 'user', 'user' => $user] : null;
    }

    // ---- Note / activities for a discussion ----

    public static function discussionUrl(Discussion $discussion): string
    {
        return self::base().'/d/'.$discussion->id;
    }

    public static function noteId(Discussion $discussion): string
    {
        return self::base().'/federation/notes/'.$discussion->id;
    }

    /** The standalone Note object for a discussion (also embedded in Create). */
    public static function noteForDiscussion(Discussion $discussion): array
    {
        $author = self::authorOf($discussion->user);
        $actor = $author ? self::userActorUrl($author) : self::actorUrl();
        $followers = $author
            ? self::base().'/federation/users/'.$author->id.'/followers'
            : self::base().'/federation/followers';
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
        $link = self::discussionUrl($discussion);
        $content = '<p><strong>'.e($discussion->title).'</strong></p>'
            .($excerpt !== '' ? '<p>'.e($excerpt).'</p>' : '')
            .'<p><a href="'.e($link).'">'.e($link).'</a></p>';

        return [
            'id' => self::noteId($discussion),
            'type' => 'Note',
            'attributedTo' => $actor,
            'content' => $content,
            'url' => $link,
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$followers],
        ];
    }

    public static function createActivityForDiscussion(Discussion $discussion): array
    {
        $note = self::noteForDiscussion($discussion);

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
    public static function announceActivityForDiscussion(Discussion $discussion): array
    {
        $noteId = self::noteId($discussion);
        $published = ($discussion->created_at ?? \Carbon\Carbon::now())->toAtomString();

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteId.'#announce',
            'type' => 'Announce',
            'actor' => self::actorUrl(),
            'published' => $published,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [self::base().'/federation/followers'],
            'object' => $noteId,
        ];
    }

    /** Resolve one of our Note URLs (or the human /d/ link) back to a Discussion. */
    public static function discussionFromUrl(?string $url): ?Discussion
    {
        if (! $url) {
            return null;
        }
        $base = self::base();
        foreach ([$base.'/federation/notes/', $base.'/d/'] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                $rest = trim(substr($url, strlen($prefix)), '/');
                $id = (int) strtok($rest, '-#?');

                return $id > 0 ? Discussion::find($id) : null;
            }
        }

        return null;
    }

    // ---- Eligibility ----

    /** Only public, visible, member-authored discussions federate. */
    public static function shouldFederate(Discussion $discussion): bool
    {
        return self::enabled()
            && ! $discussion->is_private
            && $discussion->hidden_at === null
            && $discussion->user
            && ! $discussion->user->is_federated;
    }

    // ---- Inbound remote actors ----

    /** Find-or-create a local "federated" user mirroring a remote actor. */
    public static function upsertRemoteUser(string $actorUri): ?User
    {
        $actorUri = trim($actorUri);
        if ($actorUri === '') {
            return null;
        }
        $existing = User::where('federated_actor', $actorUri)->first();
        $doc = self::fetchActor($actorUri);
        if (! $doc && ! $existing) {
            return null;
        }

        $user = $existing ?: new User;
        if ($doc) {
            $username = (string) ($doc['preferredUsername'] ?? 'user');
            $host = (string) (parse_url((string) ($doc['id'] ?? $actorUri), PHP_URL_HOST) ?: 'remote');
            $user->forceFill([
                'federated_handle' => '@'.$username.'@'.$host,
                'federated_inbox' => $doc['endpoints']['sharedInbox'] ?? ($doc['inbox'] ?? null),
                'is_federated' => true,
            ]);
        }

        if (! $existing) {
            $username = (string) ($doc['preferredUsername'] ?? ('fedi'.substr(sha1($actorUri), 0, 8)));
            $user->forceFill([
                'username' => self::uniqueLocalUsername($username),
                'federated_actor' => $actorUri,
                'email' => 'fedi-'.sha1($actorUri).'@federated.invalid',
                'password' => Str::random(40),
                'is_email_confirmed' => true,
                'is_federated' => true,
                'joined_at' => \Carbon\Carbon::now(),
            ]);
        }
        $user->save();

        return $user;
    }

    protected static function uniqueLocalUsername(string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', $base) ?: 'fediverse';
        $candidate = $base;
        $i = 0;
        while (User::where('username', $candidate)->exists()) {
            $candidate = $base.(++$i);
        }

        return $candidate;
    }

    // ---- Delivery (queued via DeliverActivity) ----

    /** Announce a brand-new discussion: community boost + author's own followers. */
    public static function announceDiscussion(Discussion $discussion): void
    {
        try {
            if (! self::shouldFederate($discussion)) {
                return;
            }
            $author = self::authorOf($discussion->user);

            // Community followers (user_id null) → a boost from the community
            // actor (the actor they follow → lands in their home timeline).
            $communityInboxes = self::inboxesFor(
                FederationFollower::whereNull('user_id')->get()
            );
            if ($communityInboxes) {
                Job\DeliverActivity::send(
                    self::announceActivityForDiscussion($discussion), $communityInboxes, null
                );
            }

            // The author's own followers → the per-member Create, signed by them.
            if ($author) {
                $authorInboxes = self::inboxesFor(
                    FederationFollower::where('user_id', $author->id)->get()
                );
                if ($authorInboxes) {
                    Job\DeliverActivity::send(
                        self::createActivityForDiscussion($discussion), $authorInboxes, $author->id
                    );
                }
            }
        } catch (\Throwable $e) {
            self::log()->debug('[federation] announceDiscussion skipped: '.$e->getMessage());
        }
    }

    /** Cross-post a local reply to followers + remote thread participants. */
    public static function announceReply(Post $post, Discussion $discussion): void
    {
        try {
            if (! ($post instanceof CommentPost) || ! self::shouldFederate($discussion)) {
                return;
            }
            // Never re-broadcast a reply that arrived FROM the fediverse.
            if ($post->user && $post->user->is_federated) {
                return;
            }
            $author = self::authorOf($post->user);
            $actor = $author ? self::userActorUrl($author) : self::actorUrl();
            $followers = $author
                ? self::base().'/federation/users/'.$author->id.'/followers'
                : self::base().'/federation/followers';
            $link = self::discussionUrl($discussion).'/'.$post->number;

            // Remote participants already in this thread.
            $remoteInboxes = User::whereIn('id', $discussion->posts()->pluck('user_id'))
                ->where('is_federated', true)->pluck('federated_inbox')->filter()->all();
            // The author's followers (replies aren't boosted to community followers
            // to avoid flooding — following the community gives you new topics).
            $followerInboxes = $author
                ? self::inboxesFor(FederationFollower::where('user_id', $author->id)->get())
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

            $activity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => self::noteId($discussion).'#post-'.$post->id,
                'type' => 'Create',
                'actor' => $actor,
                'published' => $published,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => [$followers],
                'object' => [
                    'id' => self::noteId($discussion).'#post-'.$post->id,
                    'type' => 'Note',
                    'attributedTo' => $actor,
                    'inReplyTo' => self::noteId($discussion),
                    'content' => $content,
                    'url' => $link,
                    'published' => $published,
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$followers],
                ],
            ];
            Job\DeliverActivity::send($activity, $inboxes, $author?->id);
        } catch (\Throwable $e) {
            self::log()->debug('[federation] announceReply skipped: '.$e->getMessage());
        }
    }

    /** @param \Illuminate\Support\Collection<FederationFollower> $rows */
    public static function inboxesFor($rows): array
    {
        return $rows->map(fn (FederationFollower $f) => $f->deliveryInbox())
            ->filter()->unique()->values()->all();
    }

    // ---- HTTP Signatures (draft-cavage, as Mastodon uses) ----

    /** Sign as a member ($signer) or the community ($signer null). */
    public static function signHeadersAs(?User $signer, string $method, string $url, ?string $body = null): array
    {
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $method = strtolower($method);

        $headers = ['Host' => $host, 'Date' => $date];
        $signedHeaders = ['(request-target)', 'host', 'date'];
        $lines = [
            '(request-target): '.$method.' '.$path,
            'host: '.$host,
            'date: '.$date,
        ];

        if ($body !== null) {
            $digest = 'SHA-256='.base64_encode(hash('sha256', $body, true));
            $headers['Digest'] = $digest;
            $headers['Content-Type'] = self::CTYPE; // sent, but NOT signed
            $signedHeaders[] = 'digest';
            $lines[] = 'digest: '.$digest;
        }

        $priv = $signer ? self::userKeys($signer)['private'] : self::keys()['private'];
        $keyId = ($signer ? self::userActorUrl($signer) : self::actorUrl()).'#main-key';
        openssl_sign(implode("\n", $lines), $sig, $priv, OPENSSL_ALGO_SHA256);
        $headers['Signature'] = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $signedHeaders),
            base64_encode((string) $sig),
        );

        return $headers;
    }

    /** Verify an incoming signed request against its actor's public key. */
    public static function verifyRequest(ServerRequestInterface $request, string $rawBody): bool
    {
        $sigHeader = $request->getHeaderLine('Signature');
        if ($sigHeader === '') {
            return false;
        }
        $params = self::parseSignature($sigHeader);
        if (empty($params['keyId']) || empty($params['headers']) || empty($params['signature'])) {
            return false;
        }

        $actor = self::fetchActor($params['keyId']);
        $pem = $actor['publicKey']['publicKeyPem'] ?? null;
        if (! $pem) {
            return false;
        }

        $lines = [];
        foreach (explode(' ', $params['headers']) as $h) {
            if ($h === '(request-target)') {
                $lines[] = '(request-target): '.strtolower($request->getMethod()).' '.$request->getRequestTarget();
            } elseif ($h === 'digest') {
                $lines[] = 'digest: '.$request->getHeaderLine('Digest');
            } else {
                $lines[] = $h.': '.$request->getHeaderLine($h);
            }
        }

        if (str_contains($params['headers'], 'digest')) {
            $expected = 'SHA-256='.base64_encode(hash('sha256', $rawBody, true));
            if (! hash_equals($expected, $request->getHeaderLine('Digest'))) {
                return false;
            }
        }

        return openssl_verify(implode("\n", $lines), base64_decode($params['signature']), $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array<string,string> */
    protected static function parseSignature(string $header): array
    {
        $out = [];
        preg_match_all('/(\w+)="([^"]*)"/', $header, $m, PREG_SET_ORDER);
        foreach ($m as $pair) {
            $out[$pair[1]] = $pair[2];
        }

        return $out;
    }

    /** Fetch (and cache) a remote actor document. Accepts an actor or key URL. */
    public static function fetchActor(string $url): ?array
    {
        $url = strtok($url, '#') ?: $url; // strip #main-key fragment

        return self::cache()->remember('federation:actor:'.sha1($url), 3600, function () use ($url) {
            try {
                $headers = self::signHeadersAs(null, 'get', $url);
                $headers['Accept'] = self::CTYPE;
                $res = (new Client(['timeout' => 8]))->get($url, ['headers' => $headers, 'http_errors' => false]);
                if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                    return json_decode((string) $res->getBody(), true) ?: null;
                }
            } catch (\Throwable $e) {
                self::log()->debug('[federation] fetchActor failed: '.$e->getMessage());
            }

            return null;
        });
    }

    /** POST a signed activity to a single inbox. Returns the HTTP status, or 0. */
    public static function deliver(?User $signer, string $inbox, array $activity): int
    {
        try {
            $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = self::signHeadersAs($signer, 'post', $inbox, $body);
            $res = (new Client(['timeout' => 12]))->post($inbox, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
            ]);

            return $res->getStatusCode();
        } catch (\Throwable $e) {
            self::log()->debug('[federation] delivery failed to '.$inbox.': '.$e->getMessage());

            return 0;
        }
    }

    /** Flatten remote HTML content into plain text Flarum's formatter can parse. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>#i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    protected static function cache(): Cache
    {
        return resolve(Cache::class);
    }

    protected static function log(): LoggerInterface
    {
        return resolve(LoggerInterface::class);
    }
}
