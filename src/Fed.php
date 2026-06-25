<?php

namespace ErnestDefoe\Federation;

use Flarum\User\User;

/**
 * Access layer over the {@see FederationUserData} companion row, so call sites
 * read like the old direct-column access (`$this->fed->username($user)`) without
 * scattering relation details everywhere.
 *
 * Injectable (no dependencies) so consumers can mock it in tests instead of
 * depending on the live `federationData` Eloquent relation.
 */
class Fed
{
    /** The member's federation companion row, if any (lazy-loads the relation). */
    public function data(User $user): ?FederationUserData
    {
        return $user->federationData;
    }

    /** Get-or-build the companion row (not persisted until ->save()). */
    public function ensure(User $user): FederationUserData
    {
        $data = $user->federationData;
        if (! $data) {
            $data = new FederationUserData;
            $data->user_id = $user->id;
            $user->setRelation('federationData', $data);
        }

        return $data;
    }

    public function isFederated(?User $user): bool
    {
        return (bool) ($user?->federationData?->is_federated);
    }

    public function apUsername(User $user): ?string
    {
        return $user->federationData?->ap_username;
    }

    public function federatedActor(?User $user): ?string
    {
        return $user?->federationData?->federated_actor;
    }
}
