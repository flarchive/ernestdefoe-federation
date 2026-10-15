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
 *
 * 🚨 Decided by looking, never by trying and catching. PostgreSQL runs each
 * migration in a transaction, and one failed statement aborts the transaction
 * even when PHP catches the exception: dropping the unique index that a fresh
 * install already has (on PostgreSQL a unique constraint, which DROP INDEX
 * refuses) failed the whole migration, and federation could not be enabled.
 *
 * Raw schema builder on purpose: Flarum\Database\Migration only wraps table and
 * column operations (createTable/addColumns/dropColumns) — it has no helper for
 * swapping an index's uniqueness — so there is nothing to delegate to here. The
 * builder is the same prefix-aware $schema the helpers wrap, so table prefixes
 * are still applied correctly.
 */
$idx = 'fed_user_data_ap_username_index';

return [
    'up' => function (Builder $schema) use ($idx) {
        if ($schema->hasIndex('federation_user_data', $idx, 'unique')) {
            return; // a fresh install: 000004 already made it unique
        }

        $hasPlain = $schema->hasIndex('federation_user_data', $idx);
        $duplicates = $schema->getConnection()->table('federation_user_data')
            ->select('ap_username')
            ->whereNotNull('ap_username')
            ->groupBy('ap_username')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            // Keep (or add) the plain index so resolution still works.
            if (! $hasPlain) {
                $schema->table('federation_user_data', fn (Blueprint $t) => $t->index('ap_username', $idx));
            }

            return;
        }

        $schema->table('federation_user_data', function (Blueprint $t) use ($idx, $hasPlain) {
            if ($hasPlain) {
                $t->dropIndex($idx);
            }
            $t->unique('ap_username', $idx);
        });
    },

    'down' => function (Builder $schema) use ($idx) {
        if (! $schema->hasIndex('federation_user_data', $idx, 'unique')) {
            return;
        }

        $schema->table('federation_user_data', function (Blueprint $t) use ($idx) {
            $t->dropUnique($idx);
            $t->index('ap_username', $idx);
        });
    },
];
