<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Flarum\Group\Group;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * How the forum is found from the fediverse: WebFinger, NodeInfo and the
 * actor documents. Nothing is answered while federation is switched off.
 */
class DiscoveryTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'unconfirmed', 'email' => 'unconfirmed@machine.local', 'is_email_confirmed' => 0],
                ['id' => 4, 'username' => 'alice_mirror', 'email' => 'fedi-x@federated.invalid', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 4, 'group_id' => Group::MEMBER_ID]],
            'federation_user_data' => [
                ['user_id' => 4, 'is_federated' => true, 'federated_actor' => self::REMOTE],
            ],
        ]);
    }

    public static function endpoints(): array
    {
        return [
            'webfinger' => ['/.well-known/webfinger', ['resource' => 'acct:garage@localhost']],
            'nodeinfo' => ['/.well-known/nodeinfo', []],
            'nodeinfo data' => ['/nodeinfo/2.0', []],
            'community actor' => ['/federation/actor', []],
            'community outbox' => ['/federation/outbox', []],
            'community followers' => ['/federation/followers', []],
            'member actor' => ['/federation/users/2/actor', []],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function nothing_is_answered_while_federation_is_off(string $path, array $query)
    {
        $this->setting('ernestdefoe-federation.username', 'garage');

        $this->assertSame(404, $this->get($path, $query)->getStatusCode());
    }

    #[Test]
    public function the_forum_payload_says_whether_it_federates()
    {
        $off = $this->json($this->get('/api'))['data']['attributes'];
        $this->assertFalse($off['federationEnabled']);
        $this->assertNull($off['federationHandle']);
    }

    #[Test]
    public function the_forum_payload_carries_the_community_handle()
    {
        $this->enable();

        $on = $this->json($this->get('/api'))['data']['attributes'];
        $this->assertTrue($on['federationEnabled']);
        $this->assertSame('@garage@localhost', $on['federationHandle']);
    }

    #[Test]
    public function a_members_profile_carries_their_handle_but_a_mirror_has_none()
    {
        $this->enable();

        $this->assertSame('@normal@localhost', $this->json($this->get('/api/users/2', [], 1))['data']['attributes']['federationHandle']);
        $this->assertNull($this->json($this->get('/api/users/4', [], 1))['data']['attributes']['federationHandle']);
    }

    #[Test]
    public function webfinger_finds_the_community()
    {
        $this->enable();

        $response = $this->get('/.well-known/webfinger', ['resource' => 'acct:garage@localhost']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringStartsWith('application/jrd+json', $response->getHeaderLine('Content-Type'));
        $doc = $this->json($response);
        $this->assertSame('acct:garage@localhost', $doc['subject']);
        $this->assertSame('http://localhost/federation/actor', $doc['links'][0]['href']);
    }

    #[Test]
    public function webfinger_finds_a_member_once_they_have_a_handle()
    {
        $this->enable();
        $this->fakeFediverse();

        // A handle is minted the first time the member's actor is asked for.
        $this->assertSame(200, $this->get('/federation/users/2/actor')->getStatusCode());

        $doc = $this->json($this->get('/.well-known/webfinger', ['resource' => 'acct:normal@localhost']));
        $this->assertSame('http://localhost/federation/users/2/actor', $doc['links'][0]['href']);
    }

    #[Test]
    public function webfinger_answers_for_this_host_only()
    {
        $this->enable();

        $this->assertSame(404, $this->get('/.well-known/webfinger', ['resource' => 'acct:garage@elsewhere.example'])->getStatusCode());
        $this->assertSame(404, $this->get('/.well-known/webfinger', ['resource' => 'acct:nobody@localhost'])->getStatusCode());
    }

    #[Test]
    public function the_community_actor_publishes_its_key_and_endpoints()
    {
        $this->enable();
        $this->fakeFediverse();

        $response = $this->get('/federation/actor');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringStartsWith('application/activity+json', $response->getHeaderLine('Content-Type'));
        $doc = $this->json($response);
        $this->assertSame('Group', $doc['type']);
        $this->assertSame('garage', $doc['preferredUsername']);
        $this->assertSame('http://localhost/federation/inbox', $doc['inbox']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $doc['publicKey']['publicKeyPem']);
        $this->assertStringNotContainsString('PRIVATE', (string) $response->getBody());
    }

    #[Test]
    public function a_member_is_a_person_but_not_while_unconfirmed_or_a_mirror()
    {
        $this->enable();
        $this->fakeFediverse();

        $doc = $this->json($this->get('/federation/users/2/actor'));
        $this->assertSame('Person', $doc['type']);
        $this->assertSame('normal', $doc['preferredUsername']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($doc));

        $this->assertSame(404, $this->get('/federation/users/3/actor')->getStatusCode(), 'Unconfirmed');
        $this->assertSame(404, $this->get('/federation/users/4/actor')->getStatusCode(), 'A mirror of a remote account');
        $this->assertSame(404, $this->get('/federation/users/99/actor')->getStatusCode());
    }

    #[Test]
    public function nodeinfo_counts_only_local_members()
    {
        $this->enable();
        $this->fakeFediverse();

        $this->assertSame('http://localhost/nodeinfo/2.0', $this->json($this->get('/.well-known/nodeinfo'))['links'][0]['href']);

        $data = $this->json($this->get('/nodeinfo/2.0'));
        $this->assertSame(['activitypub'], $data['protocols']);
        $this->assertSame(3, $data['usage']['users']['total'], 'The admin, the member and the unconfirmed account; not the mirror');
    }
}
