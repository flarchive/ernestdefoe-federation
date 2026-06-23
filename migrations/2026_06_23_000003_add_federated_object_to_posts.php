<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The remote object URI a post was imported from (inbound federated reply), so
 * we de-duplicate redeliveries and can act on a remote Delete.
 */
$column = Migration::addColumns('posts', [
    'federated_object' => ['string', 'length' => 500, 'nullable' => true],
]);

return [
    'up' => function (Builder $schema) use ($column) {
        $column['up']($schema);
        $schema->table('posts', function (Blueprint $table) {
            $table->index('federated_object', 'posts_federated_object_index');
        });
    },
    'down' => function (Builder $schema) use ($column) {
        $schema->table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_federated_object_index');
        });
        $column['down']($schema);
    },
];
