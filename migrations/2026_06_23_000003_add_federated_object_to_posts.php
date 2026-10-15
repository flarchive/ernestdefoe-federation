<?php

use Illuminate\Database\Schema\Builder;

/*
 * Squashed. This migration originally added federated_object to the core posts
 * table; 2026_06_24_000005 then moved it into the post_federation_meta companion
 * table. Existing installs already ran the original and have the value migrated
 * across by 000005; for FRESH installs this is now a no-op so we don't ALTER the
 * (high-volume) posts table to add a column only to drop it moments later.
 */
return [
    'up' => function (Builder $schema) {},
    'down' => function (Builder $schema) {},
];
