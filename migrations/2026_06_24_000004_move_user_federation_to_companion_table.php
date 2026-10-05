<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Move per-member federation data off the core users table into a companion
 * table (federation_user_data) — the bulky key columns and federation indexes no
 * longer bloat every user row / mutation. Existing data added by the original
 * 2026_06_23_000002 is copied across, then the old columns + indexes are dropped.
 *
 * Table creation + column removal use the Flarum\Database\Migration helpers; the
 * data copy stays a raw, driver-agnostic, prefix-safe query-builder chunk.
 */

$FIELDS = [
    'ap_username', 'ap_public_key', 'ap_private_key', 'is_federated',
    'federated_actor', 'federated_handle', 'federated_inbox',
];

$companion = Migration::createTable('federation_user_data', function (Blueprint $table) {
    // core users.id is INT UNSIGNED → unsignedInteger FK (errno 3780 otherwise).
    $table->unsignedInteger('user_id')->primary();
    $table->string('ap_username', 80)->nullable();
    $table->text('ap_public_key')->nullable();
    $table->text('ap_private_key')->nullable();
    $table->boolean('is_federated')->default(false);
    $table->string('federated_actor', 500)->nullable();
    $table->string('federated_handle', 255)->nullable();
    $table->string('federated_inbox', 500)->nullable();
    $table->timestamps();
    // Unique: a member's ap_username must be globally unique (WebFinger resolves
    // by it). Mirrors leave it NULL, and NULLs are distinct in a unique index.
    $table->unique('ap_username', 'fed_user_data_ap_username_index');
    $table->index('federated_actor', 'fed_user_data_federated_actor_index');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});

// Removal of the legacy core-table columns; the helper's `down` re-adds them.
$userCols = Migration::dropColumns('users', [
    'ap_username' => ['string', 'length' => 80, 'nullable' => true],
    'ap_public_key' => ['text', 'nullable' => true],
    'ap_private_key' => ['text', 'nullable' => true],
    'is_federated' => ['boolean', 'default' => false],
    'federated_actor' => ['string', 'length' => 500, 'nullable' => true],
    'federated_handle' => ['string', 'length' => 255, 'nullable' => true],
    'federated_inbox' => ['string', 'length' => 500, 'nullable' => true],
]);

return [
    'up' => function (Builder $schema) use ($companion, $userCols, $FIELDS) {
        if (! $schema->hasTable('federation_user_data')) {
            $companion['up']($schema);
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
        $userCols['up']($schema);
    },

    'down' => function (Builder $schema) use ($companion, $userCols, $FIELDS) {
        $userCols['down']($schema); // re-add the columns

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
            $companion['down']($schema);
        }
    },
];
