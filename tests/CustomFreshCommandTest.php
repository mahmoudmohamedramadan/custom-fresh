<?php

namespace Ramadan\CustomFresh\Tests;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Ramadan\CustomFresh\Console\Commands\WrappedMigrateFreshCommand;
use Ramadan\CustomFresh\Events\DatabaseRefreshed;
use Ramadan\CustomFresh\Events\RefreshingDatabase;
use Ramadan\CustomFresh\Events\TablesDropped;
use Ramadan\CustomFresh\Support\ForeignKeyAdvisor;
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
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
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
        config()->set('custom-fresh.always_keep', ['cf_users', 'cf_oauth_tokens']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--except' => 'cf_users'])->assertSuccessful();

        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertTrue(Schema::hasTable('cf_oauth_tokens'));
        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_except_does_not_drop_a_referenced_parent_of_a_kept_child()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        config()->set('custom-fresh.always_keep', ['cf_posts']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--except' => 'cf_users'])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
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
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
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

    public function test_drop_mode_does_not_drop_a_parent_of_a_kept_child()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--drop' => 'cf_users'])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
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
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
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
            '--keep'            => 'cf_users',
            '--drop-referenced' => true,
            '--seed-fresh'      => true,
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

        $this->freshCustom($path, ['--keep' => 'cf_cache_locks'])
            ->expectsOutputToContain(
                'Also preserving [cf_cache] because it shares a create migration with [cf_cache_locks].'
            )
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_cache'));
        $this->assertTrue(Schema::hasTable('cf_cache_locks'));
        $this->assertTrue(Schema::hasTable('cf_cache_entries'));
        $this->assertSame('kept-cache', DB::table('cf_cache')->value('value'));
        $this->assertSame('owner-1', DB::table('cf_cache_locks')->value('owner'));
        $this->assertSame('remembered', DB::table('cf_cache_entries')->value('cache_key'));
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

        $this->freshCustom($path, ['--drop' => 'cf_cache'])
            ->expectsOutputToContain(
                'Cannot drop [cf_cache]; it shares a create migration with [cf_cache_locks].'
            )
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_cache'));
        $this->assertSame('kept-cache', DB::table('cf_cache')->value('value'));
        $this->assertSame('owner-1', DB::table('cf_cache_locks')->value('owner'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_confirming_a_shared_migration_drop_drops_all_siblings()
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

        $this->artisan('fresh:custom', [
            '--drop'     => 'cf_cache',
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])
            ->expectsOutputToContain(
                'Dropping [cf_cache] may affect related tables: [cf_cache_locks].'
            )
            ->expectsConfirmation('Drop all these tables?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('cf_cache')->count());
        $this->assertSame(0, DB::table('cf_cache_locks')->count());
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_declining_a_shared_migration_drop_keeps_the_siblings()
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

        $this->artisan('fresh:custom', [
            '--drop'     => 'cf_cache',
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])
            ->expectsOutputToContain(
                'Dropping [cf_cache] may affect related tables: [cf_cache_locks].'
            )
            ->expectsConfirmation('Drop all these tables?', 'no')
            ->doesntExpectOutputToContain('Cannot drop')
            ->doesntExpectOutputToContain('Dropping the tables')
            ->doesntExpectOutputToContain('Nothing to migrate')
            ->assertSuccessful();

        $this->assertSame('kept-cache', DB::table('cf_cache')->value('value'));
        $this->assertSame('owner-1', DB::table('cf_cache_locks')->value('owner'));
    }

    public function test_confirming_a_shared_migration_drop_also_drops_dependents()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

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

        $this->artisan('fresh:custom', [
            '--drop'     => 'cf_users',
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])
            ->expectsOutputToContain(
                'Dropping [cf_users] may affect related tables: [cf_password_reset_tokens], [cf_posts], [cf_sessions].'
            )
            ->expectsConfirmation('Drop all these tables?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertSame(0, DB::table('cf_sessions')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_keeping_a_child_also_preserves_referenced_parents()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_posts'])
            ->expectsOutputToContain('cf_users')
            ->doesntExpectOutputToContain('--drop-referenced')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_posts'));
        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_positional_child_table_also_preserves_referenced_parents()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['tables' => 'cf_posts'])
            ->expectsOutputToContain(
                'Also preserving [cf_users] because [cf_posts] references it.'
            )
            ->doesntExpectOutputToContain(
                'Preserved table [cf_posts] references [cf_users], which cannot be dropped.'
            )
            ->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_keeping_a_parent_also_preserves_child_tables()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_users'])
            ->expectsOutputToContain('cf_posts')
            ->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
        $this->assertSame(0, DB::table('cf_oauth_tokens')->count());
    }

    public function test_drop_referenced_does_not_drop_a_parent_of_a_kept_child()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'            => 'cf_posts',
            '--drop-referenced' => true,
        ])
            ->expectsOutputToContain(
                'Preserved table [cf_posts] references [cf_users], which cannot be dropped.'
            )
            ->doesntExpectOutputToContain(
                'Also preserving [cf_users] because [cf_posts] references it.'
            )
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_posts'));
        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_drop_referenced_drops_children_of_a_kept_parent()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'            => 'cf_users',
            '--drop-referenced' => true,
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_always_keep_child_also_preserves_referenced_parents()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        config()->set('custom-fresh.always_keep', ['cf_posts']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path)
            ->expectsOutputToContain(
                'Also preserving [cf_users] because [cf_posts] references it.'
            )
            ->doesntExpectOutputToContain(
                'Preserved table [cf_posts] references [cf_users], which cannot be dropped.'
            )
            ->assertSuccessful();

        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_always_keep_child_with_drop_referenced_still_keeps_the_parent()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        config()->set('custom-fresh.always_keep', ['cf_posts']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--drop-referenced' => true])
            ->expectsOutputToContain(
                'Preserved table [cf_posts] references [cf_users], which cannot be dropped.'
            )
            ->doesntExpectOutputToContain(
                'Also preserving [cf_users] because [cf_posts] references it.'
            )
            ->assertSuccessful();

        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
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
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_positional_argument_preserves_tables()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['tables' => 'cf_users'])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_positional_argument_combines_with_keep_option()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            'tables' => 'cf_users',
            '--keep' => 'cf_oauth_tokens',
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_always_keep_preserves_tables_without_cli_flags()
    {
        config()->set('custom-fresh.always_keep', ['cf_users']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path)->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_config_patterns_preserve_matching_tables()
    {
        config()->set('custom-fresh.patterns', ['cf_oauth_*']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path)->assertSuccessful();

        $this->assertSame('abc123', DB::table('cf_oauth_tokens')->value('token'));
        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
    }

    public function test_keep_without_migrations_config_preserves_raw_tables()
    {
        config()->set('custom-fresh.keep_without_migrations', ['cf_sessions']);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        Schema::create('cf_sessions', function ($table) {
            $table->string('id')->primary();
            $table->text('payload');
        });

        DB::table('cf_sessions')->insert([
            'id'      => 'session-1',
            'payload' => 'kept',
        ]);

        $this->freshCustom($path, ['--keep' => 'cf_users'])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_sessions'));
        $this->assertSame('kept', DB::table('cf_sessions')->value('payload'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_drop_wins_over_keep()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep' => 'cf_users,cf_oauth_tokens',
            '--drop' => 'cf_oauth_tokens',
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame(0, DB::table('cf_oauth_tokens')->count());
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_drop_does_not_remove_a_referenced_parent_of_a_kept_child()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep' => 'cf_posts',
            '--drop' => 'cf_users',
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_drop_does_not_remove_a_child_of_a_kept_parent()
    {
        if (! method_exists(Schema::connection('testing'), 'getForeignKeys')) {
            $this->markTestSkipped('Schema::getForeignKeys is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep' => 'cf_users',
            '--drop' => 'cf_posts',
        ])->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_explain_prints_the_resolved_plan()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'    => 'cf_users',
            '--explain' => true,
        ])
            ->expectsOutputToContain('testing')
            ->expectsOutputToContain('cf_users')
            ->doesntExpectOutputToContain('Also preserving')
            ->assertSuccessful();

        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_explain_json_includes_pending_alters()
    {
        $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $path = $this->migrationPath($this->allMigrations);

        $output = $this->freshCustomOutput($path, [
            '--keep'    => 'cf_users',
            '--explain' => true,
            '--json'    => true,
        ]);

        $this->assertStringContainsString('"pending_alters"', $output);
        $this->assertStringContainsString('add_phone_to_cf_users_table', $output);
        $this->assertFalse(Schema::hasColumn('cf_users', 'phone'));
    }

    public function test_list_json_maps_tables_to_migrations()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $output = $this->freshCustomOutput($path, [
            '--list' => true,
            '--json' => true,
        ]);

        $this->assertStringContainsString('"cf_users"', $output);
        $this->assertStringContainsString('0001_01_01_000000_create_cf_users_table.php', $output);
    }

    public function test_it_notes_when_a_dropped_child_references_a_kept_parent()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $relations = (new ForeignKeyAdvisor('testing'))->describeRelations(
            ['cf_users'],
            ['cf_posts', 'cf_oauth_tokens']
        );

        if ($relations['notes'] === [] && $relations['warnings'] === []) {
            $this->markTestSkipped('Foreign key metadata is not available on this driver.');
        }

        $this->assertContains(
            'Dropped table [cf_posts] references preserved table [cf_users].',
            $relations['notes']
        );
        $this->assertSame([], $relations['warnings']);

        $output = $this->freshCustomOutput($path, [
            '--keep'            => 'cf_users',
            '--drop-referenced' => true,
        ]);

        $this->assertStringContainsString(
            'Dropped table [cf_posts] references preserved table [cf_users].',
            $output
        );
        $this->assertStringNotContainsString(
            'WARN  Dropped table [cf_posts] references preserved table [cf_users].',
            $output
        );
    }

    public function test_it_dispatches_lifecycle_events()
    {
        Event::fake([
            RefreshingDatabase::class,
            TablesDropped::class,
            DatabaseRefreshed::class,
        ]);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_users'])->assertSuccessful();

        Event::assertDispatched(RefreshingDatabase::class, function ($event) {
            return $event->connection === 'testing'
                && in_array('cf_users', $event->preserved, true);
        });

        Event::assertDispatched(TablesDropped::class, function ($event) {
            return in_array('cf_users', $event->preserved, true)
                && in_array('cf_oauth_tokens', $event->dropped, true);
        });

        Event::assertDispatched(DatabaseRefreshed::class, function ($event) {
            return $event->connection === 'testing'
                && in_array('cf_users', $event->preserved, true);
        });
    }

    public function test_graceful_returns_success_for_an_unknown_preset()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path, [
            '--preset'   => 'missing',
            '--graceful' => true,
        ])->assertSuccessful();
    }

    public function test_seed_warns_when_preserved_tables_still_have_data()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'   => 'cf_users',
            '--seed'   => true,
            '--seeder' => PostSeeder::class,
        ])
            ->expectsOutputToContain('DatabaseSeeder')
            ->assertSuccessful();
    }

    public function test_seed_fresh_warns_when_no_seeders_match()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'       => 'cf_users',
            '--seed-fresh' => true,
        ])
            ->expectsOutputToContain('table_seeders')
            ->assertSuccessful();
    }

    public function test_drop_types_warns_on_sqlite()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, [
            '--keep'       => 'cf_users',
            '--drop-types' => true,
        ])
            ->expectsOutputToContain('--drop-types')
            ->assertSuccessful();
    }

    public function test_drop_views_removes_leftover_views()
    {
        if (! method_exists(Schema::connection('testing'), 'dropAllViews')) {
            $this->markTestSkipped('Schema::dropAllViews is not available.');
        }

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        DB::statement('CREATE VIEW cf_posts_view AS SELECT id, title FROM cf_posts');

        $this->freshCustom($path, [
            '--keep'       => 'cf_users',
            '--drop-views' => true,
        ])->assertSuccessful();

        $views = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'view'"))
            ->pluck('name')
            ->all();

        $this->assertNotContains('cf_posts_view', $views);
    }

    public function test_database_option_targets_the_named_connection()
    {
        config()->set('database.connections.tenant', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        $path = $this->migrationPath($this->baseMigrations);

        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])->assertSuccessful();

        DB::connection('tenant')->table('cf_users')->insert([
            'email'      => 'tenant@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('fresh:custom', [
            'tables'           => 'cf_users',
            '--database'       => 'tenant',
            '--path'           => [$path],
            '--realpath'       => true,
            '--force'          => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame('tenant@example.com', DB::connection('tenant')->table('cf_users')->value('email'));
        $this->assertSame(0, DB::connection('tenant')->table('cf_oauth_tokens')->count());
    }

    public function test_it_skips_tables_without_migrations_unless_keep_raw()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        Schema::create('cf_sessions', function ($table) {
            $table->string('id')->primary();
            $table->text('payload');
        });

        $this->freshCustom($path, ['--keep' => 'cf_users,cf_sessions'])
            ->expectsOutputToContain('keep-raw')
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('cf_sessions'));
        $this->assertSame('kept@example.com', DB::table('cf_users')->value('email'));
    }

    public function test_it_warns_when_a_kept_table_does_not_exist()
    {
        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->freshCustom($path, ['--keep' => 'cf_users,cf_future'])
            ->expectsOutputToContain('Table [cf_future] does not exist.')
            ->assertSuccessful();
    }

    public function test_it_fails_when_the_only_kept_table_does_not_exist()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path, ['--keep' => 'usersss'])
            ->expectsOutputToContain('Table [usersss] does not exist.')
            ->assertFailed();
    }

    public function test_confirm_in_blocks_without_force()
    {
        config()->set('custom-fresh.confirm_in', ['testing']);

        $path = $this->migrateFixtures($this->baseMigrations);

        $this->artisan('fresh:custom', [
            '--keep'     => 'cf_users',
            '--path'     => [$path],
            '--realpath' => true,
        ])
            ->expectsConfirmation('Do you really wish to run this command?', 'no')
            ->assertFailed();
    }

    public function test_it_publishes_the_config_file()
    {
        $file = $this->app->configPath('custom-fresh.php');

        @unlink($file);

        $this->artisan('vendor:publish', [
            '--tag'   => 'custom-fresh-config',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFileExists($file);
        $this->assertStringContainsString('replace_migrate_fresh', file_get_contents($file));

        @unlink($file);
    }

    public function test_it_fails_when_nothing_is_resolved()
    {
        $path = $this->migrateFixtures($this->baseMigrations);

        $this->freshCustom($path)
            ->expectsOutputToContain('No tables to preserve were specified.')
            ->assertFailed();
    }

    public function test_migrate_fresh_command_can_be_resolved()
    {
        $command = $this->app->make(FreshCommand::class);

        $this->assertInstanceOf(WrappedMigrateFreshCommand::class, $command);
    }

    public function test_migrate_fresh_wrapper_runs_laravel_fresh_when_disabled()
    {
        config()->set('custom-fresh.replace_migrate_fresh', false);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->artisan('migrate:fresh', [
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
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
        $this->assertSame('original-post', DB::table('cf_posts')->value('title'));
    }

    public function test_migrate_fresh_wrapper_skips_delegation_when_keep_lists_are_empty()
    {
        config()->set('custom-fresh.replace_migrate_fresh', true);
        config()->set('custom-fresh.always_keep', []);
        config()->set('custom-fresh.patterns', []);
        config()->set('custom-fresh.keep_without_migrations', []);

        $path = $this->migrateFixtures($this->baseMigrations);
        $this->seedKeptRow();

        $this->artisan('migrate:fresh', [
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cf_users'));
        $this->assertSame(0, DB::table('cf_users')->count());
        $this->assertSame(0, DB::table('cf_posts')->count());
    }
}
