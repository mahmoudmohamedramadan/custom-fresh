<?php

namespace Ramadan\CustomFresh\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomFreshSharedMigrationTest extends TestCase
{
    /**
     * Laravel's default users migration creates users, password_reset_tokens,
     * and sessions together. Keeping only sessions must also keep the siblings
     * so migrate does not try to recreate them.
     *
     * @return void
     */
    public function test_it_keeps_users_when_only_sessions_is_preserved()
    {
        $path = $this->migrateFixtures([
            '0001_01_01_000005_create_cf_auth_tables.php',
            '0001_01_01_000001_create_cf_posts_table.php',
        ]);

        $userId = DB::table('cf_users')->insertGetId([
            'email'      => 'kept@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cf_posts')->insert([
            'user_id'    => $userId,
            'title'      => 'original-post',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cf_sessions')->insert([
            'id'            => 'session-1',
            'user_id'       => $userId,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'payload'       => 'kept',
            'last_activity' => time(),
        ]);

        $this->freshCustom($path, ['--keep' => 'cf_sessions'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertTrue(Schema::hasTable('cf_password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cf_sessions'));
        $this->assertTrue(Schema::hasTable('cf_posts'));

        $this->assertDatabaseHas('cf_users', ['email' => 'kept@example.com']);
        $this->assertDatabaseHas('cf_sessions', ['id' => 'session-1']);
        $this->assertSame(0, DB::table('cf_posts')->count());
    }
}
