<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Remote fediverse actors that follow either the whole community (user_id NULL)
 * or one local member (user_id = that member). One row per (followed, follower).
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('federation_followers')) {
            return;
        }

        $schema->create('federation_followers', function (Blueprint $table) {
            $table->increments('id');
            // The local member being followed, or NULL = the community actor.
            $table->unsignedInteger('user_id')->nullable();
            $table->string('actor', 500);          // the remote actor URI
            $table->string('inbox', 500);          // the remote actor's personal inbox
            $table->string('shared_inbox', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'actor'], 'federation_followers_unique');
            $table->index('user_id');
            // core users.id is INT UNSIGNED → unsignedInteger FK (errno 3780 otherwise).
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('federation_followers');
    },
];
