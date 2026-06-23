<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Make federation_user_data.ap_username UNIQUE (fresh installs already get this
 * from 000004; this upgrades installs that ran the earlier non-unique 000004).
 * A member's ap_username is what WebFinger resolves on, so a duplicate would
 * silently shadow one of the two members.
 *
 * Defensive: if pre-existing duplicates make the unique index impossible, keep
 * the plain index rather than failing the whole upgrade.
 */
$idx = 'fed_user_data_ap_username_index';

return [
    'up' => function (Builder $schema) use ($idx) {
        try {
            $schema->table('federation_user_data', fn (Blueprint $t) => $t->dropIndex($idx));
        } catch (\Throwable $e) {
            // not present
        }
        try {
            $schema->table('federation_user_data', fn (Blueprint $t) => $t->unique('ap_username', $idx));
        } catch (\Throwable $e) {
            // duplicates exist — fall back to a plain index so resolution still works
            try {
                $schema->table('federation_user_data', fn (Blueprint $t) => $t->index('ap_username', $idx));
            } catch (\Throwable $e2) {
                // already present
            }
        }
    },

    'down' => function (Builder $schema) use ($idx) {
        try {
            $schema->table('federation_user_data', fn (Blueprint $t) => $t->dropIndex($idx));
        } catch (\Throwable $e) {
            // not present
        }
        try {
            $schema->table('federation_user_data', fn (Blueprint $t) => $t->index('ap_username', $idx));
        } catch (\Throwable $e) {
            // already present
        }
    },
];
