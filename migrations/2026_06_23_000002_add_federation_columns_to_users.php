<?php

use Illuminate\Database\Schema\Builder;

/*
 * Squashed. This migration originally added per-member federation columns to the
 * core users table; 2026_06_24_000004 then moved them into the
 * federation_user_data companion table. Existing installs already ran the
 * original and have those columns migrated across by 000004; for FRESH installs
 * this is now a no-op so we don't ALTER users to add columns only to drop them
 * moments later (a metadata-lock stall on large tables, for no net gain).
 */
return [
    'up' => function (Builder $schema) {},
    'down' => function (Builder $schema) {},
];
