<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Per-member federation data. Local members get a lazily-generated Person actor
 * (ap_username + keypair). Remote fediverse users are mirrored as local
 * "federated" accounts (is_federated = true) so their replies can attach to a
 * discussion; those carry their origin actor URI, handle and inbox.
 *
 * Private keys (ap_private_key) are stored encrypted at rest by the KeyManager
 * service — the column itself just holds the ciphertext.
 */
$columns = Migration::addColumns('users', [
    'ap_username' => ['string', 'length' => 80, 'nullable' => true],
    'ap_public_key' => ['text', 'nullable' => true],
    'ap_private_key' => ['text', 'nullable' => true],
    'is_federated' => ['boolean', 'default' => false],
    'federated_actor' => ['string', 'length' => 500, 'nullable' => true],
    'federated_handle' => ['string', 'length' => 255, 'nullable' => true],
    'federated_inbox' => ['string', 'length' => 500, 'nullable' => true],
]);

return [
    'up' => function (Builder $schema) use ($columns) {
        $columns['up']($schema);
        $schema->table('users', function (Blueprint $table) {
            $table->index('ap_username', 'users_ap_username_index');
            $table->index('federated_actor', 'users_federated_actor_index');
        });
    },
    'down' => function (Builder $schema) use ($columns) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropIndex('users_ap_username_index');
            $table->dropIndex('users_federated_actor_index');
        });
        $columns['down']($schema);
    },
];
