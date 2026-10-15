<?php

namespace ErnestDefoe\Federation\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Federation\Tests\integration\FederationTestCase;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/**
 * Schema changes commit on MySQL whatever transaction the test runs in, so
 * every test leaves the table as it found it: unique, and empty of its rows.
 *
 * 000006 upgrades installs whose ap_username index was not unique, and must
 * be harmless on installs where it already is, on every database.
 */
class UsernameIndexMigrationTest extends FederationTestCase
{
    private const IDX = 'fed_user_data_ap_username_index';

    private function migration(): array
    {
        return require __DIR__.'/../../../migrations/2026_06_24_000006_make_ap_username_unique.php';
    }

    #[Test]
    public function it_changes_nothing_where_the_index_is_already_unique()
    {
        $schema = $this->database()->getSchemaBuilder();

        $this->migration()['up']($schema);

        $this->assertTrue($schema->hasIndex('federation_user_data', self::IDX, 'unique'));
    }

    #[Test]
    public function it_makes_a_plain_index_unique()
    {
        $schema = $this->database()->getSchemaBuilder();
        $schema->table('federation_user_data', function (Blueprint $t) {
            $t->dropUnique(self::IDX);
            $t->index('ap_username', self::IDX);
        });
        $this->assertFalse($schema->hasIndex('federation_user_data', self::IDX, 'unique'));

        $this->migration()['up']($schema);

        $this->assertTrue($schema->hasIndex('federation_user_data', self::IDX, 'unique'));
    }

    #[Test]
    public function it_keeps_the_plain_index_when_duplicates_make_unique_impossible()
    {
        $schema = $this->database()->getSchemaBuilder();
        $schema->table('federation_user_data', function (Blueprint $t) {
            $t->dropUnique(self::IDX);
            $t->index('ap_username', self::IDX);
        });
        $this->database()->table('users')->insert([
            ['id' => 5, 'username' => 'a', 'email' => 'a@machine.local', 'password' => '', 'joined_at' => Carbon::now()],
            ['id' => 6, 'username' => 'b', 'email' => 'b@machine.local', 'password' => '', 'joined_at' => Carbon::now()],
        ]);
        $this->database()->table('federation_user_data')->insert([
            ['user_id' => 5, 'ap_username' => 'same'],
            ['user_id' => 6, 'ap_username' => 'same'],
        ]);

        try {
            $this->migration()['up']($schema);

            $this->assertTrue($schema->hasIndex('federation_user_data', self::IDX));
            $this->assertFalse($schema->hasIndex('federation_user_data', self::IDX, 'unique'));
        } finally {
            $this->database()->table('federation_user_data')->whereIn('user_id', [5, 6])->delete();
            $this->database()->table('users')->whereIn('id', [5, 6])->delete();
            $this->migration()['up']($schema);
        }
    }
}
