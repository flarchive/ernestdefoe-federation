<?php

namespace ErnestDefoe\Federation\Service;

use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * Mirrors a remote fediverse actor as a local "federated" user so its replies can
 * attach to a discussion. The mirror carries the origin actor URI, handle and
 * inbox, and is flagged is_federated so it never re-broadcasts.
 */
class RemoteUserSync
{
    public function __construct(
        protected ActorFetcher $fetcher,
    ) {}

    /** Find-or-create a local "federated" user mirroring a remote actor. */
    public function upsert(string $actorUri): ?User
    {
        $actorUri = trim($actorUri);
        if ($actorUri === '') {
            return null;
        }
        $existing = User::where('federated_actor', $actorUri)->first();
        $doc = $this->fetcher->fetchActor($actorUri);
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
                'username' => $this->uniqueLocalUsername($username),
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

    private function uniqueLocalUsername(string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', $base) ?: 'fediverse';
        $candidate = $base;
        $i = 0;
        while (User::where('username', $candidate)->exists()) {
            // Bounded: after enough collisions, fall back to a random suffix so
            // an HTTP request can never spin here indefinitely.
            if (++$i > 100) {
                return 'fedi-'.substr(sha1($base.random_bytes(4)), 0, 12);
            }
            $candidate = $base.$i;
        }

        return $candidate;
    }
}
