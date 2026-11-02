<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use ErnestDefoe\Federation\Service\RemoteUserSync;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;

/**
 * One remote server can mint at most ten mirror accounts an hour. The cap is
 * claimed with an atomic increment, and a refusal must not eat the forum-wide
 * allowance other servers share.
 */
class MirrorCapTest extends FederationTestCase
{
    #[Test]
    public function one_server_gets_ten_new_mirrors_an_hour_and_others_are_unaffected()
    {
        $this->enable();
        $this->fakeFediverse();

        $sync = $this->app()->getContainer()->make(RemoteUserSync::class);
        $mirror = function (string $host, int $n) use ($sync) {
            $url = "https://$host/users/u$n";
            $this->remoteActor($url);

            return $sync->upsert($url);
        };

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotNull($mirror('busy.example', $i), "mirror $i of 10");
        }
        $this->assertNull($mirror('busy.example', 11), 'The eleventh new account from one server is refused');
        $this->assertNotNull($mirror('busy.example', 1), 'An existing mirror is still found');
        $this->assertNotNull($mirror('quiet.example', 1), 'Another server is unaffected');

        $slot = intdiv(time(), 3600);
        $cache = $this->app()->getContainer()->make(Cache::class);
        $this->assertSame(11, (int) $cache->get("federation:new-mirrors:all:$slot"), 'The refusal did not count against the forum-wide total');
    }
}
