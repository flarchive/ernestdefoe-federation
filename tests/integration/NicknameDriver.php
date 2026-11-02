<?php

namespace ErnestDefoe\Federation\Tests\integration;

use Flarum\User\DisplayName\DriverInterface;
use Flarum\User\User;

/** Stands in for a nicknames extension: a display name that isn't the username. */
class NicknameDriver implements DriverInterface
{
    public function displayName(User $user): string
    {
        return 'Ζωή Admin';
    }
}
