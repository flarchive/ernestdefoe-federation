<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\Fed;
use ErnestDefoe\Federation\FederationUserData;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Settings + identity for the federation actor(s): reads the extension's stored
 * settings, derives the community/member handles and the canonical AP URLs.
 *
 * Injected wherever identity is needed instead of reaching into the container at
 * call time, so the dependency is explicit and the class is unit-testable.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-federation.';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
    ) {}

    public function get(string $key, $default = null)
    {
        return $this->settings->get(self::PREFIX.$key, $default);
    }

    public function set(string $key, $value): void
    {
        $this->settings->set(self::PREFIX.$key, $value);
    }

    public function raw(string $key, $default = null)
    {
        return $this->settings->get($key, $default);
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    public function base(): string
    {
        return rtrim((string) $this->config->url(), '/');
    }

    public function host(): string
    {
        return (string) (parse_url($this->base(), PHP_URL_HOST) ?: 'localhost');
    }

    public function username(): string
    {
        $u = (string) $this->get('username', '');
        if ($u !== '') {
            return $u;
        }
        $title = (string) $this->raw('forum_title', 'community');

        return Str::slug($title, '_') ?: 'community';
    }

    public function actorUrl(): string
    {
        return $this->base().'/federation/actor';
    }

    public function handle(): string
    {
        return '@'.$this->username().'@'.$this->host();
    }

    public function iconUrl(): ?string
    {
        $logo = $this->raw('logo_path') ?: $this->raw('favicon_path');

        return $logo ? $this->base().'/assets/'.$logo : null;
    }

    // ---- Per-member (Person) identity --------------------------------------

    public function displayName(User $user): string
    {
        return (string) ($user->display_name ?: $user->username);
    }

    /** A member's unique fediverse username (lazy: slug of name, deduped by id). */
    public function userUsername(User $user): string
    {
        if ($ap = Fed::apUsername($user)) {
            return $ap;
        }

        $conn = FederationUserData::query()->getConnection();

        return $conn->transaction(function () use ($user, $conn) {
            // Lock the row so concurrent fetches for THIS user serialise.
            $conn->table('federation_user_data')->insertOrIgnore(['user_id' => $user->id]);
            $data = FederationUserData::whereKey($user->id)->lockForUpdate()->first();
            $user->setRelation('federationData', $data);
            if ($data->ap_username) {
                return $data->ap_username;
            }

            $base = Str::slug($this->displayName($user), '_') ?: 'member';
            $candidate = $base;
            // Only real members hold an ap_username (mirrors leave it null).
            if (strcasecmp($candidate, $this->username()) === 0
                || FederationUserData::where('ap_username', $candidate)->where('is_federated', false)->exists()) {
                $candidate = $base.$user->id;
            }

            try {
                $data->ap_username = $candidate;
                $data->save();
            } catch (QueryException $e) {
                // Lost the race for the bare slug against another new user — the
                // unique index rejected it. Fall back to the id-suffixed form,
                // which is unique per user.
                $candidate = $base.$user->id;
                $data->ap_username = $candidate;
                $data->save();
            }

            return $candidate;
        });
    }

    /** The member's username WITHOUT persisting (safe during serialization). */
    public function previewUserUsername(User $user): string
    {
        if ($ap = Fed::apUsername($user)) {
            return $ap;
        }

        return Str::slug($this->displayName($user), '_') ?: 'member';
    }

    public function userActorUrl(User $user): string
    {
        return $this->base().'/federation/users/'.$user->id.'/actor';
    }

    public function userHandle(User $user): string
    {
        return '@'.$this->userUsername($user).'@'.$this->host();
    }

    public function previewUserHandle(User $user): string
    {
        return '@'.$this->previewUserUsername($user).'@'.$this->host();
    }

    /** Resolve a webfinger username to the community OR a local member. */
    public function resolveUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        if ($username === strtolower($this->username())) {
            return ['type' => 'community'];
        }
        $data = FederationUserData::where('ap_username', $username)->where('is_federated', false)->first();
        $user = $data?->user;

        return $user ? ['type' => 'user', 'user' => $user] : null;
    }

    /** Raw access to the on-disk Flarum config (used to derive an at-rest key). */
    public function config(): Config
    {
        return $this->config;
    }
}
