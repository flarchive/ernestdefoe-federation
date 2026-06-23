<?php

namespace ErnestDefoe\Federation\Api;

use ErnestDefoe\Federation\Service\Settings;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * User-resource federation field (the member's previewed fediverse handle).
 * Invokable so {@see Settings} is injected via the constructor — see
 * {@see ForumFederationFields}.
 */
class UserFederationFields
{
    public function __construct(
        protected Settings $settings,
    ) {}

    /** @return array<int, \Tobyz\JsonApiServer\Schema\Field\Field> */
    public function __invoke(): array
    {
        return [
            Schema\Str::make('federationHandle')
                ->nullable()
                ->get(fn (User $user) => ($this->settings->enabled() && ! $user->is_federated)
                    ? $this->settings->previewUserHandle($user)
                    : null),
        ];
    }
}
