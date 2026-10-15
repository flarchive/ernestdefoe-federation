<?php

namespace ErnestDefoe\Federation;

use Flarum\Database\AbstractModel;

/**
 * A remote fediverse actor following the community (user_id null) or a member.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $actor
 * @property string $inbox
 * @property string|null $shared_inbox
 */
class FederationFollower extends AbstractModel
{
    protected $table = 'federation_followers';

    /** Explicit allow-list — populated from inbound Follow activities. */
    protected $fillable = ['user_id', 'actor', 'inbox', 'shared_inbox'];

    /** The inbox to deliver to — the shared inbox if the server exposes one. */
    public function deliveryInbox(): ?string
    {
        return $this->shared_inbox ?: $this->inbox;
    }
}
