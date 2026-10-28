<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use ErnestDefoe\Federation\Tests\integration\NicknameDriver;
use Flarum\Extend;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A member's handle comes from their username, while a nickname is only the
 * display name, so it can't steer, empty or copy anyone's handle.
 */
class HandleTest extends FederationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extend((new Extend\User())->displayNameDriver('nick', NicknameDriver::class));
        $this->setting('display_name_driver', 'nick');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'old-timer', 'email' => 'old@machine.local', 'is_email_confirmed' => 1],
            ],
            'federation_user_data' => [
                ['user_id' => 3, 'ap_username' => 'already_minted', 'is_federated' => false],
            ],
        ]);
    }

    #[Test]
    public function the_handle_is_the_username_and_the_nickname_is_the_name()
    {
        $this->enable();
        $this->fakeFediverse();

        $this->assertSame('@normal@localhost', $this->json($this->get('/api/users/2', [], 1))['data']['attributes']['federationHandle']);

        $doc = $this->json($this->get('/federation/users/2/actor'));
        $this->assertSame('normal', $doc['preferredUsername']);
        $this->assertSame('Ζωή Admin', $doc['name']);

        $finger = $this->json($this->get('/.well-known/webfinger', ['resource' => 'acct:normal@localhost']));
        $this->assertSame('http://localhost/federation/users/2/actor', $finger['links'][0]['href']);
    }

    #[Test]
    public function a_handle_minted_before_keeps_working()
    {
        $this->enable();
        $this->fakeFediverse();

        $this->assertSame('already_minted', $this->json($this->get('/federation/users/3/actor'))['preferredUsername']);
        $this->assertSame(404, $this->get('/.well-known/webfinger', ['resource' => 'acct:old_timer@localhost'])->getStatusCode());
    }
}
