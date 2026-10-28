<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use ErnestDefoe\Federation\Tests\integration\FakeActorFetcher;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Flarum\Group\Group;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A discussion held for approval is not public yet: it goes out when a
 * moderator approves it, not when it is posted.
 */
class ApprovalTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'federation_followers' => [
                ['user_id' => null, 'actor' => self::REMOTE, 'inbox' => self::REMOTE.'/inbox'],
            ],
        ]);

        $this->enable();
    }

    protected function extensions(): array
    {
        // In the order a forum boots them: federation lists approval as an
        // optional dependency.
        return ['flarum-flags', 'flarum-approval', 'ernestdefoe-federation'];
    }

    #[Test]
    public function a_held_discussion_goes_out_once_approved()
    {
        $this->fakeFediverse();
        $this->database()->table('group_permission')->where('group_id', Group::MEMBER_ID)->where('permission', 'like', '%WithoutApproval')->delete();

        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Held', 'content' => 'Waiting for a moderator.']]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $postId = json_decode((string) $response->getBody(), true)['data']['relationships']['firstPost']['data']['id'];

        $this->assertSame([], FakeActorFetcher::$deliveries, 'Not while it waits');

        $response = $this->send($this->request('PATCH', "/api/posts/$postId", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $postId, 'attributes' => ['isApproved' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(['Announce'], array_map(fn ($d) => $d['activity']['type'], FakeActorFetcher::$deliveries));
    }
}
