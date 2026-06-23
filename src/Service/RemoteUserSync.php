<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\FederationUserData;
use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * Mirrors a remote fediverse actor as a local "federated" user so its replies can
 * attach to a discussion. The mirror's federation data (origin actor URI, handle,
 * inbox, is_federated flag) lives in the federation_user_data companion table.
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
        if ($actorUri === '' || strlen($actorUri) > 500) {
            return null;
        }
        $existing = FederationUserData::where('federated_actor', $actorUri)->first()?->user;
        $doc = $this->fetcher->fetchActor($actorUri);
        if (! $doc && ! $existing) {
            return null;
        }

        $user = $existing ?: new User;

        // Core user row first (a new mirror needs an id before the companion FK).
        if (! $existing) {
            $username = (string) ($doc['preferredUsername'] ?? ('fedi'.substr(sha1($actorUri), 0, 8)));
            $user->forceFill([
                'username' => $this->uniqueLocalUsername($username),
                'email' => 'fedi-'.sha1($actorUri).'@federated.invalid',
                'password' => Str::random(40),
                'is_email_confirmed' => true,
                'joined_at' => \Carbon\Carbon::now(),
            ]);
            $user->save();
        }

        // Federation data → companion table.
        $fed = Fed::ensure($user);
        $fed->is_federated = true;
        $fed->federated_actor = $actorUri;
        if ($doc) {
            $username = (string) ($doc['preferredUsername'] ?? 'user');
            $host = (string) (parse_url((string) ($doc['id'] ?? $actorUri), PHP_URL_HOST) ?: 'remote');
            $handle = '@'.$username.'@'.$host;
            $inbox = $doc['endpoints']['sharedInbox'] ?? ($doc['inbox'] ?? null);
            // Skip over-long remote values rather than throwing on the insert.
            $fed->federated_handle = strlen($handle) <= 255 ? $handle : null;
            $fed->federated_inbox = ($inbox && strlen((string) $inbox) <= 500) ? $inbox : null;
        }
        $fed->save();

        return $user;
    }

    private function uniqueLocalUsername(string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', $base) ?: 'fediverse';

        // One query instead of up to 100 DB round-trips on the inbox request:
        // pull existing collisions ({base}% — a superset is harmless) and resolve
        // the first free suffix in PHP. Usernames are matched case-insensitively.
        $taken = User::where('username', 'like', $base.'%')
            ->pluck('username')
            ->map(fn ($u) => strtolower((string) $u))
            ->flip();

        if (! $taken->has(strtolower($base))) {
            return $base;
        }
        for ($i = 1; $i <= 1000; $i++) {
            if (! $taken->has(strtolower($base.$i))) {
                return $base.$i;
            }
        }

        return 'fedi-'.substr(sha1($base.random_bytes(4)), 0, 12);
    }
}
