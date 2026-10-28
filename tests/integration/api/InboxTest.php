<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use ErnestDefoe\Federation\Tests\integration\FakeActorFetcher;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The inbox takes activities from anybody on the internet, so a signature
 * that does not hold up must change nothing.
 */
class InboxTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
        $this->enable();
    }

    private function follow(string $actor = self::REMOTE, string $object = 'http://localhost/federation/actor'): array
    {
        return ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $actor.'#follow/1', 'type' => 'Follow', 'actor' => $actor, 'object' => $object];
    }

    private function followers(): array
    {
        return $this->database()->table('federation_followers')->orderBy('id')->pluck('actor')->all();
    }

    #[Test]
    public function an_unsigned_activity_is_refused()
    {
        $this->fakeFediverse();

        $response = $this->postRaw('/federation/inbox', json_encode($this->follow()), []);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->followers());
    }

    #[Test]
    public function an_oversized_activity_is_refused()
    {
        $this->fakeFediverse();

        $response = $this->postRaw('/federation/inbox', '{}', ['Signature' => 'keyId="x"', 'Content-Length' => '70000']);

        $this->assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function a_signed_follow_is_recorded_and_accepted()
    {
        $this->fakeFediverse();
        $key = $this->remoteActor();

        $response = $this->postSigned('/federation/inbox', $this->follow(), $key);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([self::REMOTE], $this->followers());

        $this->assertCount(1, FakeActorFetcher::$deliveries);
        $accept = FakeActorFetcher::$deliveries[0];
        $this->assertSame(self::REMOTE.'/inbox', $accept['inbox']);
        $this->assertSame('Accept', $accept['activity']['type']);
        $this->assertSame('http://localhost/federation/actor', $accept['activity']['actor']);
    }

    #[Test]
    public function a_follow_of_a_member_is_recorded_against_them()
    {
        $this->fakeFediverse();
        $key = $this->remoteActor();

        $this->postSigned('/federation/users/2/inbox', $this->follow(self::REMOTE, 'http://localhost/federation/users/2/actor'), $key);

        $this->assertSame([2], $this->database()->table('federation_followers')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, FakeActorFetcher::$deliveries[0]['signer'], 'Accepted by the member followed');
    }

    #[Test]
    public function a_signature_by_another_key_changes_nothing()
    {
        $this->fakeFediverse();
        $this->remoteActor();
        $impostor = $this->remoteActor('https://remote.example/users/mallory');
        // Mallory signs, but names Alice's key.
        $response = $this->postSigned('/federation/inbox', $this->follow(), $impostor);

        $this->assertSame(202, $response->getStatusCode(), 'Answered before verification, then dropped');
        $this->assertSame([], $this->followers());
        $this->assertSame([], FakeActorFetcher::$deliveries);
    }

    #[Test]
    public function an_actor_may_not_follow_in_someone_elses_name()
    {
        $this->fakeFediverse();
        $key = $this->remoteActor();
        $this->remoteActor('https://remote.example/users/bob');

        // Signed by Alice, claiming to be Bob.
        $this->postSigned('/federation/inbox', $this->follow('https://remote.example/users/bob'), $key);

        $this->assertSame([], $this->followers());
    }

    #[Test]
    public function a_stale_signature_is_dropped()
    {
        $this->fakeFediverse();
        $key = $this->remoteActor();

        $this->postSigned('/federation/inbox', $this->follow(), $key, self::REMOTE.'#main-key', gmdate('D, d M Y H:i:s', time() - 3600).' GMT');

        $this->assertSame([], $this->followers());
    }

    #[Test]
    public function a_replayed_request_is_accepted_once()
    {
        $this->fakeFediverse();
        $key = $this->remoteActor();
        $date = gmdate('D, d M Y H:i:s').' GMT';

        $this->postSigned('/federation/inbox', $this->follow(), $key, self::REMOTE.'#main-key', $date);
        $this->postSigned('/federation/inbox', $this->follow(), $key, self::REMOTE.'#main-key', $date);

        $this->assertCount(1, FakeActorFetcher::$deliveries, 'One Accept, not two');
    }

    #[Test]
    public function the_inbox_is_closed_while_federation_is_off()
    {
        $this->setting('ernestdefoe-federation.enabled', '0');
        $this->fakeFediverse();
        $key = $this->remoteActor();

        $this->assertSame(404, $this->postSigned('/federation/inbox', $this->follow(), $key)->getStatusCode());
        $this->assertSame([], $this->followers());
    }
}
