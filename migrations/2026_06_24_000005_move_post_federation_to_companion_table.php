<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Move the inbound-reply marker (federated_object) off the high-volume core
 * posts table into a companion table (post_federation_meta), so the column +
 * index don't widen every post row. Existing values added by
 * 2026_06_23_000003 are copied across, then the old column + index are dropped.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('post_federation_meta')) {
            $schema->create('post_federation_meta', function (Blueprint $table) {
                // core posts.id is INT UNSIGNED → unsignedInteger FK (errno 3780 otherwise).
                $table->unsignedInteger('post_id')->primary();
                $table->string('federated_object', 500)->nullable();
                $table->timestamps();
                $table->index('federated_object', 'post_fed_meta_object_index');
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            });
        }

        if (! $schema->hasColumn('posts', 'federated_object')) {
            return; // fresh install — nothing to migrate
        }

        $db = $schema->getConnection();
        $db->table('posts')
            ->whereNotNull('federated_object')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($db) {
                $batch = [];
                foreach ($rows as $r) {
                    $batch[] = ['post_id' => $r->id, 'federated_object' => $r->federated_object];
                }
                if ($batch) {
                    $db->table('post_federation_meta')->insertOrIgnore($batch);
                }
            });

        try {
            $schema->table('posts', fn (Blueprint $t) => $t->dropIndex('posts_federated_object_index'));
        } catch (\Throwable $e) {
            // already gone
        }
        $schema->table('posts', function (Blueprint $table) {
            $table->dropColumn('federated_object');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('posts', 'federated_object')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->string('federated_object', 500)->nullable();
                $table->index('federated_object', 'posts_federated_object_index');
            });
        }

        if ($schema->hasTable('post_federation_meta')) {
            $db = $schema->getConnection();
            $db->table('post_federation_meta')->orderBy('post_id')->chunkById(500, function ($rows) use ($db) {
                foreach ($rows as $r) {
                    $db->table('posts')->where('id', $r->post_id)->update(['federated_object' => $r->federated_object]);
                }
            }, 'post_id');
            $schema->drop('post_federation_meta');
        }
    },
];
