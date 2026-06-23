<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The remote object URI a post was imported from (inbound federated reply), so
 * we de-duplicate redeliveries and can act on a remote Delete.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('posts', 'federated_object')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->string('federated_object', 500)->nullable();
                $table->index('federated_object', 'posts_federated_object_index');
            });
        }
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('posts', 'federated_object')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->dropIndex('posts_federated_object_index');
                $table->dropColumn('federated_object');
            });
        }
    },
];
