<?php

namespace ErnestDefoe\Federation\Tests\integration;

use ErnestDefoe\Federation\Service\ActorFetcher;
use Flarum\User\User;

/**
 * The fediverse, as far as the tests are concerned: actor documents served
 * from a map, and every delivery recorded instead of sent. No test reaches
 * the network.
 */
class FakeActorFetcher extends ActorFetcher
{
    /** @var array<string, array<string, mixed>> actor URL => document */
    public static array $actors = [];

    /** @var list<array{signer: ?int, inbox: string, activity: array<string, mixed>}> */
    public static array $deliveries = [];

    public function __construct()
    {
    }

    public function fetchActor(string $url): ?array
    {
        return self::$actors[strtok($url, '#') ?: $url] ?? null;
    }

    public function deliver(?User $signer, string $inbox, array $activity): int
    {
        self::$deliveries[] = ['signer' => $signer?->id, 'inbox' => $inbox, 'activity' => $activity];

        return 202;
    }
}
