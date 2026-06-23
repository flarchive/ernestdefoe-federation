<?php

namespace ErnestDefoe\Federation;

use Flarum\User\User;

/**
 * Thin access layer over the {@see FederationUserData} companion row, so call
 * sites read like the old direct-column access (`Fed::username($user)`) without
 * scattering relation/whereHas details everywhere.
 */
class Fed
{
    /** The member's federation companion row, if any (lazy-loads the relation). */
    public static function data(User $user): ?FederationUserData
    {
        return $user->federationData;
    }

    /** Get-or-build the companion row (not persisted until ->save()). */
    public static function ensure(User $user): FederationUserData
    {
        $data = $user->federationData;
        if (! $data) {
            $data = new FederationUserData;
            $data->user_id = $user->id;
            $user->setRelation('federationData', $data);
        }

        return $data;
    }

    public static function isFederated(?User $user): bool
    {
        return (bool) ($user?->federationData?->is_federated);
    }

    public static function apUsername(User $user): ?string
    {
        return $user->federationData?->ap_username;
    }

    public static function federatedActor(?User $user): ?string
    {
        return $user?->federationData?->federated_actor;
    }
}
