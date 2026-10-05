<?php

namespace ErnestDefoe\Federation\Api;

use ErnestDefoe\Federation\Service\Settings;
use Flarum\Api\Schema;

/**
 * Forum-resource federation fields. An invokable class (not a closure) so Flarum
 * resolves it through the container and {@see Settings} arrives via constructor
 * injection — no `resolve()` / global service locator.
 */
class ForumFederationFields
{
    public function __construct(
        protected Settings $settings,
    ) {}

    /** @return array<int, \Tobyz\JsonApiServer\Schema\Field\Field> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('federationEnabled')
                ->get(fn () => $this->settings->enabled()),
            Schema\Str::make('federationHandle')
                ->nullable()
                ->get(fn () => $this->settings->enabled() ? $this->settings->handle() : null),
        ];
    }
}
