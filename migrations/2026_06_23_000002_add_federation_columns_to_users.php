<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Per-member federation data. Local members get a lazily-generated Person actor
 * (ap_username + keypair). Remote fediverse users are mirrored as local
 * "federated" accounts (is_federated = true) so their replies can attach to a
 * discussion; those carry their origin actor URI, handle and inbox.
 */
return [
    'up' => function (Builder $schema) {
        $columns = [
            'ap_username' => fn (Blueprint $t) => $t->string('ap_username', 80)->nullable(),
            'ap_public_key' => fn (Blueprint $t) => $t->text('ap_public_key')->nullable(),
            'ap_private_key' => fn (Blueprint $t) => $t->text('ap_private_key')->nullable(),
            'is_federated' => fn (Blueprint $t) => $t->boolean('is_federated')->default(false),
            'federated_actor' => fn (Blueprint $t) => $t->string('federated_actor', 500)->nullable(),
            'federated_handle' => fn (Blueprint $t) => $t->string('federated_handle', 255)->nullable(),
            'federated_inbox' => fn (Blueprint $t) => $t->string('federated_inbox', 500)->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (! $schema->hasColumn('users', $name)) {
                $schema->table('users', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            }
        }

        // Indexes (guarded so a re-run won't fail).
        try {
            $schema->table('users', function (Blueprint $table) {
                $table->index('ap_username', 'users_ap_username_index');
            });
        } catch (\Throwable $e) {
            // already exists
        }
        try {
            $schema->table('users', function (Blueprint $table) {
                $table->index('federated_actor', 'users_federated_actor_index');
            });
        } catch (\Throwable $e) {
            // already exists
        }
    },

    'down' => function (Builder $schema) {
        foreach ([
            'ap_username', 'ap_public_key', 'ap_private_key', 'is_federated',
            'federated_actor', 'federated_handle', 'federated_inbox',
        ] as $name) {
            if ($schema->hasColumn('users', $name)) {
                $schema->table('users', function (Blueprint $table) use ($name) {
                    $table->dropColumn($name);
                });
            }
        }
    },
];
