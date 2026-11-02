<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the forum publishes: only what a guest could read, written by its own
 * members.
 */
class OutboxTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $t = Carbon::now()->subDay();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 4, 'username' => 'alice_mirror', 'email' => 'fedi-x@federated.invalid', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 4, 'group_id' => Group::MEMBER_ID]],
            'federation_user_data' => [
                ['user_id' => 4, 'is_federated' => true, 'federated_actor' => self::REMOTE],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Public', 'created_at' => $t, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Private', 'created_at' => $t, 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1, 'is_private' => true],
                ['id' => 3, 'title' => 'Hidden', 'created_at' => $t, 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1, 'hidden_at' => $t],
                ['id' => 4, 'title' => 'From the fediverse', 'created_at' => $t, 'user_id' => 4, 'first_post_id' => 4, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello fediverse</p></t>', 'created_at' => $t],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>', 'created_at' => $t],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Removed</p></t>', 'created_at' => $t],
                ['id' => 4, 'discussion_id' => 4, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Mirrored</p></t>', 'created_at' => $t],
            ],
        ]);

        $this->enable();
    }

    #[Test]
    public function the_outbox_holds_only_public_discussions_by_local_members()
    {
        $this->fakeFediverse();

        $this->assertSame(1, $this->json($this->get('/federation/outbox'))['totalItems']);

        $page = $this->json($this->get('/federation/outbox', ['page' => '1']));
        $this->assertSame('OrderedCollectionPage', $page['type']);
        $this->assertCount(1, $page['orderedItems']);
        $this->assertSame('Create', $page['orderedItems'][0]['type']);
        $this->assertStringContainsString('Hello fediverse', json_encode($page['orderedItems'][0]['object']));
    }

    #[Test]
    public function a_public_discussion_is_a_note()
    {
        $this->fakeFediverse();

        $response = $this->get('/federation/notes/1');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $note = $this->json($response);
        $this->assertSame('http://localhost/federation/notes/1', $note['id']);
        $this->assertStringContainsString('Hello fediverse', $note['content']);
    }

    #[Test]
    public function a_private_hidden_or_mirrored_discussion_is_not_published_as_a_note()
    {
        $this->fakeFediverse();

        foreach ([2, 3, 4, 99] as $id) {
            $this->assertSame(404, $this->get("/federation/notes/$id")->getStatusCode(), "Discussion $id");
        }
    }

    #[Test]
    public function an_outbox_page_costs_the_same_queries_however_many_discussions_it_holds()
    {
        $this->fakeFediverse();
        $page = fn () => $this->get('/federation/outbox', ['page' => '1']);

        $page();
        $few = $this->queries($page);

        $t = Carbon::now()->subHours(2);
        for ($d = 10; $d <= 15; $d++) {
            $user = $d;
            $this->database()->table('users')->insert(['id' => $user, 'username' => "u$d", 'email' => "u$d@machine.local", 'password' => '', 'is_email_confirmed' => 1, 'joined_at' => $t]);
            $this->database()->table('discussions')->insert(['id' => $d, 'title' => "D$d", 'slug' => "d$d", 'created_at' => $t, 'user_id' => $user, 'comment_count' => 1]);
            $this->database()->table('posts')->insert(['id' => $d * 10, 'discussion_id' => $d, 'number' => 1, 'user_id' => $user, 'type' => 'comment', 'content' => '<t><p>x</p></t>', 'created_at' => $t]);
            $this->database()->table('discussions')->where('id', $d)->update(['first_post_id' => $d * 10]);
        }

        $this->assertSame($few, $this->queries($page));
    }

    private function queries(callable $request): int
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $response = $request();
        $db->disableQueryLog();
        $this->assertSame(200, $response->getStatusCode());

        /*
         * 🚨 Core's CollectGarbage middleware deletes expired access, email,
         * password and registration tokens on a random 2% of requests — four
         * DELETEs that have nothing to do with the outbox. When the lottery
         * hit one of the two measured requests, the counts differed by exactly
         * four (CI: "7 is identical to 11", PHP 8.4 / MySQL 8.4 only). They
         * are left out of the count. The prefix is matched because CI runs
         * prefixed databases, and the quotes are optional because each driver
         * quotes differently.
         */
        $prefix = preg_quote($db->getTablePrefix(), '/');
        $garbage = '/^delete from [`"]?'.$prefix.'(access|email|password|registration)_tokens[`"]?/i';

        return count(array_filter($db->getQueryLog(), fn (array $query) => ! preg_match($garbage, $query['query'])));
    }
}
