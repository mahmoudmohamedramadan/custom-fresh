<?php

namespace Ramadan\CustomFresh\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramadan\CustomFresh\Tests\Fixtures\Seeders\PostSeeder;

class CustomFreshCommandTest extends TestCase
{
    public function test_it_preserves_specified_tables_and_drops_the_rest()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_users'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame(0, DB::table('cf_posts')->count());
        $this->assertSame(0, DB::table('cf_oauth_tokens')->count());
    }

    public function test_it_runs_pending_alters_on_preserved_tables()
    {
        $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $path = $this->migrationPath($this->allMigrations);

        $this->freshCustom($path, ['--keep' => 'cf_users'])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('cf_users', 'phone'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_freeze_schema_skips_pending_alters_on_preserved_tables()
    {
        $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $path = $this->migrationPath($this->allMigrations);

        $this->freshCustom($path, [
            '--keep'          => 'cf_users',
            '--freeze-schema' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('cf_users', 'phone'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_except_drops_a_table_from_always_keep()
    {
        config()->set('custom-fresh.always_keep', ['cf_users', 'cf_posts']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--except' => 'cf_users'])->assertSuccessful();

        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertTrue(Schema::hasTable('cf_posts'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_keep_raw_preserves_tables_without_migrations()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        Schema::create('cf_sessions', function ($table) {
            $table->string('id')->primary();
            $table->text('payload');
        });

        DB::table('cf_sessions')->insert([
            'id' => 'session-1',
            'payload' => 'kept',
        ]);

        $this->freshCustom($path, [
            '--keep' => 'cf_users',
            '--keep-raw' => 'cf_sessions',
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_sessions'));
        $this->assertSame('kept', DB::table('cf_sessions')->value('payload'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_drop_mode_only_removes_listed_tables()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--drop' => 'cf_posts'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertTrue(Schema::hasTable('cf_oauth_tokens'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_glob_patterns_preserve_matching_tables()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_oauth_*'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_oauth_tokens'));
        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_preset_expands_configured_tables()
    {
        config()->set('custom-fresh.presets', [
            'auth' => ['cf_users', 'cf_oauth_*'],
        ]);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--preset' => 'auth'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertTrue(Schema::hasTable('cf_oauth_tokens'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_unknown_preset_fails()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path, ['--preset' => 'missing'])->assertFailed();
    }

    public function test_explain_json_lists_preserve_and_drop()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path, [
            '--keep' => 'cf_users',
            '--explain' => true,
            '--json' => true,
        ])->expectsOutputToContain('"preserve"')->assertSuccessful();
    }

    public function test_list_shows_discovered_tables()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path, ['--list' => true])
            ->expectsOutputToContain('cf_users')
            ->assertSuccessful();
    }

    public function test_seed_fresh_only_seeds_dropped_tables()
    {
        config()->set('custom-fresh.table_seeders', [
            'cf_posts' => PostSeeder::class,
        ]);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep' => 'cf_users',
            '--seed-fresh' => true,
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame(1, DB::table('cf_users')->count());
        $this->assertSame('seeded-post', DB::table('cf_posts')->value('title'));
    }

    public function test_it_preserves_sibling_tables_from_the_same_create_migration()
    {
        $path = $this->migrateFixtures(array_merge($this->baseMigrations, [
            '0001_01_01_000003_create_cf_cache_tables.php',
            '0001_01_01_000004_create_cf_cache_entries_table.php',
        ]));
        $this->seedKeptRow();

        DB::table('cf_cache')->insert([
            'key'   => 'remembered',
            'value' => 'kept-cache',
        ]);
        DB::table('cf_cache_locks')->insert([
            'key'   => 'lock-1',
            'owner' => 'owner-1',
        ]);
        DB::table('cf_cache_entries')->insert([
            'cache_key'  => 'remembered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->freshCustom($path, ['--keep' => 'cf_cache_locks'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_cache'));
        $this->assertTrue(Schema::hasTable('cf_cache_locks'));
        $this->assertTrue(Schema::hasTable('cf_cache_entries'));
        $this->assertSame('kept-cache', DB::table('cf_cache')->value('value'));
        $this->assertSame('owner-1', DB::table('cf_cache_locks')->value('owner'));
        $this->assertSame(0, DB::table('cf_cache_entries')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_drop_does_not_remove_a_create_sibling_of_a_preserved_table()
    {
        $path = $this->migrateFixtures(array_merge($this->baseMigrations, [
            '0001_01_01_000003_create_cf_cache_tables.php',
        ]));
        $this->seedKeptRow();

        DB::table('cf_cache')->insert([
            'key'   => 'remembered',
            'value' => 'kept-cache',
        ]);
        DB::table('cf_cache_locks')->insert([
            'key'   => 'lock-1',
            'owner' => 'owner-1',
        ]);

        $this->freshCustom($path, ['--drop' => 'cf_cache'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_cache'));
        $this->assertSame('kept-cache', DB::table('cf_cache')->value('value'));
        $this->assertSame('owner-1', DB::table('cf_cache_locks')->value('owner'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_with_related_also_preserves_foreign_key_parents()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'         => 'cf_posts',
            '--with-related' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_posts'));
        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_connection_overrides_merge_always_keep()
    {
        config()->set('custom-fresh.always_keep', ['cf_users']);
        config()->set('custom-fresh.connections.testing.always_keep', ['cf_oauth_tokens']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path)->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_it_fails_when_nothing_is_resolved()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path)->assertFailed();
    }

    public function test_migrate_fresh_wrapper_delegates_when_enabled()
    {
        config()->set('custom-fresh.replace_migrate_fresh', true);
        config()->set('custom-fresh.always_keep', ['cf_users']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->artisan('migrate:fresh', [
            '--path' => [$path],
            '--realpath' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }
}
