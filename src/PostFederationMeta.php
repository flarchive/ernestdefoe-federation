<?php

namespace ErnestDefoe\Federation;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The remote object URI a post was imported from (inbound federated reply), kept
 * in a companion table so the column + index don't widen every row on the
 * high-volume core posts table.
 *
 * @property int $post_id
 * @property string|null $federated_object
 */
class PostFederationMeta extends AbstractModel
{
    protected $table = 'post_federation_meta';

    protected $primaryKey = 'post_id';

    public $incrementing = false;

    /** Explicit allow-list — written from inbound federated payloads. */
    protected $fillable = ['post_id', 'federated_object'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
