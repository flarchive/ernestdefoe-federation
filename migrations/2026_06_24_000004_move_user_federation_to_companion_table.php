<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Move per-member federation data off the core users table into a companion
 * table (federation_user_data). The bulky key columns and the two federation
 * indexes no longer bloat every user row / mutation on large forums.
 *
 * Existing data added by 2026_06_23_000002 is copied across, then the old
 * columns + indexes are dropped. The copy uses the query builder so it stays
 * driver-agnostic and respects any table prefix.
 */

$FIELDS = [
    'ap_username', 'ap_public_key', 'ap_private_key', 'is_federated',
    'federated_actor', 'federated_handle', 'federated_inbox',
];

$USER_COLUMN_DEFS = [
    'ap_username' => fn (Blueprint $t) => $t->string('ap_username', 80)->nullable(),
    'ap_public_key' => fn (Blueprint $t) => $t->text('ap_public_key')->nullable(),
    'ap_private_key' => fn (Blueprint $t) => $t->text('ap_private_key')->nullable(),
    'is_federated' => fn (Blueprint $t) => $t->boolean('is_federated')->default(false),
    'federated_actor' => fn (Blueprint $t) => $t->string('federated_actor', 500)->nullable(),
    'federated_handle' => fn (Blueprint $t) => $t->string('federated_handle', 255)->nullable(),
    'federated_inbox' => fn (Blueprint $t) => $t->string('federated_inbox', 500)->nullable(),
];

return [
    'up' => function (Builder $schema) use ($FIELDS) {
        if (! $schema->hasTable('federation_user_data')) {
            $schema->create('federation_user_data', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->string('ap_username', 80)->nullable();
                $table->text('ap_public_key')->nullable();
                $table->text('ap_private_key')->nullable();
                $table->boolean('is_federated')->default(false);
                $table->string('federated_actor', 500)->nullable();
                $table->string('federated_handle', 255)->nullable();
                $table->string('federated_inbox', 500)->nullable();
                $table->timestamps();
                // Unique: a member's ap_username must be globally unique (WebFinger
                // resolves by it). Remote mirrors leave it NULL, and NULLs are
                // distinct in a unique index, so they don't collide.
                $table->unique('ap_username', 'fed_user_data_ap_username_index');
                $table->index('federated_actor', 'fed_user_data_federated_actor_index');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $schema->hasColumn('users', 'ap_username')) {
            return; // fresh install — nothing to migrate
        }

        $db = $schema->getConnection();
        $db->table('users')
            ->where(function ($q) use ($FIELDS) {
                foreach ($FIELDS as $f) {
                    $f === 'is_federated' ? $q->orWhere('is_federated', 1) : $q->orWhereNotNull($f);
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($db, $FIELDS) {
                $batch = [];
                foreach ($rows as $r) {
                    $row = ['user_id' => $r->id];
                    foreach ($FIELDS as $f) {
                        $row[$f] = $f === 'is_federated' ? (int) ($r->is_federated ?? 0) : ($r->$f ?? null);
                    }
                    $batch[] = $row;
                }
                if ($batch) {
                    $db->table('federation_user_data')->insertOrIgnore($batch);
                }
            });

        foreach (['users_ap_username_index', 'users_federated_actor_index'] as $idx) {
            try {
                $schema->table('users', fn (Blueprint $t) => $t->dropIndex($idx));
            } catch (\Throwable $e) {
                // already gone
            }
        }
        $schema->table('users', function (Blueprint $table) use ($FIELDS) {
            $table->dropColumn($FIELDS);
        });
    },

    'down' => function (Builder $schema) use ($FIELDS, $USER_COLUMN_DEFS) {
        foreach ($USER_COLUMN_DEFS as $name => $add) {
            if (! $schema->hasColumn('users', $name)) {
                $schema->table('users', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            }
        }

        if ($schema->hasTable('federation_user_data')) {
            $db = $schema->getConnection();
            $db->table('federation_user_data')->orderBy('user_id')->chunkById(500, function ($rows) use ($db, $FIELDS) {
                foreach ($rows as $r) {
                    $update = [];
                    foreach ($FIELDS as $f) {
                        $update[$f] = $r->$f;
                    }
                    $db->table('users')->where('id', $r->user_id)->update($update);
                }
            }, 'user_id');
            $schema->drop('federation_user_data');
        }
    },
];
