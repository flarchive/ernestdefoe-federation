<?php

namespace ErnestDefoe\Federation;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-member federation data, kept in a companion table instead of on the core
 * users table so the bulky key columns and the federation indexes don't bloat
 * every user row / mutation on large forums.
 *
 * @property int $user_id
 * @property string|null $ap_username
 * @property string|null $ap_public_key
 * @property string|null $ap_private_key  encrypted at rest (see KeyManager)
 * @property bool $is_federated
 * @property string|null $federated_actor
 * @property string|null $federated_handle
 * @property string|null $federated_inbox
 */
class FederationUserData extends AbstractModel
{
    protected $table = 'federation_user_data';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['is_federated' => 'bool'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
