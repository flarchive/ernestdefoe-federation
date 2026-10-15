<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Federation\Tests\integration\FakeActorFetcher;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * New discussions go out to the community's followers, and replies to the
 * author's; nothing that is not public goes anywhere.
 */
class AnnounceTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $earlier = Carbon::now()->subHour();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'federation_followers' => [
                ['user_id' => null, 'actor' => self::REMOTE, 'inbox' => self::REMOTE.'/inbox', 'shared_inbox' => 'https://remote.example/inbox'],
                ['user_id' => 2, 'actor' => 'https://other.example/users/bob', 'inbox' => 'https://other.example/users/bob/inbox'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Existing', 'created_at' => $earlier, 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Start</p></t>', 'created_at' => $earlier],
            ],
        ]);

        $this->enable();
    }

    private function start(int $actor = 2): void
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $actor,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Big news', 'content' => 'Something happened today.']]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array<string, string> inbox => activity type */
    private function deliveries(): array
    {
        return array_column(array_map(fn ($d) => [$d['inbox'], $d['activity']['type']], FakeActorFetcher::$deliveries), 1, 0);
    }

    #[Test]
    public function a_new_discussion_reaches_the_community_and_the_authors_followers()
    {
        $this->fakeFediverse();

        $this->start();

        $this->assertSame([
            'https://remote.example/inbox' => 'Announce',
            'https://other.example/users/bob/inbox' => 'Create',
        ], $this->deliveries());

        $create = FakeActorFetcher::$deliveries[1];
        $this->assertSame(2, $create['signer'], 'Signed by the author');
        $this->assertStringContainsString('Something happened today.', json_encode($create['activity']));
    }

    #[Test]
    public function a_reply_reaches_the_authors_followers_only()
    {
        $this->fakeFediverse();

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'A reply'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(['https://other.example/users/bob/inbox' => 'Create'], $this->deliveries());
    }

    #[Test]
    public function nothing_goes_out_while_federation_is_off()
    {
        $this->setting('ernestdefoe-federation.enabled', '0');
        $this->fakeFediverse();

        $this->start();

        $this->assertSame([], FakeActorFetcher::$deliveries);
    }

    #[Test]
    public function a_reply_in_a_private_discussion_goes_nowhere()
    {
        $this->prepareDatabase([
            Discussion::class => [['id' => 2, 'title' => 'Private', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 2, 'is_private' => true]],
            Post::class => [
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Start</p></t>', 'created_at' => Carbon::now()],
                ['id' => 3, 'discussion_id' => 2, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>', 'created_at' => Carbon::now()],
            ],
        ]);
        $this->fakeFediverse();

        // Members cannot post into a private discussion without an extension
        // that grants it, so the event is raised as such an extension would.
        $this->app()->getContainer()->make('events')->dispatch(new Posted(CommentPost::query()->find(3)));

        $this->assertSame([], FakeActorFetcher::$deliveries);
    }
}
