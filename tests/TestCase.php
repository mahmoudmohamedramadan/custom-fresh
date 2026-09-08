<?php

namespace Ramadan\CustomFresh\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ramadan\CustomFresh\CustomFreshServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * The files that create the base schema without pending alters.
     *
     * @var array<int, string>
     */
    protected array $baseMigrations = [
        '0001_01_01_000000_create_cf_users_table.php',
        '0001_01_01_000001_create_cf_posts_table.php',
        '0001_01_01_000002_create_cf_oauth_tokens_table.php',
    ];

    /**
     * Every fixture migration, including pending alters.
     *
     * @var array<int, string>
     */
    protected array $allMigrations = [
        '0001_01_01_000000_create_cf_users_table.php',
        '0001_01_01_000001_create_cf_posts_table.php',
        '0001_01_01_000002_create_cf_oauth_tokens_table.php',
        '0001_01_01_000006_add_phone_to_cf_users_table.php',
    ];

    /**
     * Temporary directories created during a test.
     *
     * @var array<int, string>
     */
    protected array $tempDirectories = [];

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            CustomFreshServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('custom-fresh.always_keep', []);
        $app['config']->set('custom-fresh.patterns', []);
        $app['config']->set('custom-fresh.keep_without_migrations', []);
        $app['config']->set('custom-fresh.presets', []);
        $app['config']->set('custom-fresh.table_seeders', []);
        $app['config']->set('custom-fresh.connections', []);
        $app['config']->set('custom-fresh.replace_migrate_fresh', false);
        $app['config']->set('custom-fresh.confirm_in', []);
    }

    /**
     * Clean up temporary migration directories.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    /**
     * Copy fixture migrations into a temporary directory and return its path.
     *
     * @param  array<int, string>  $files
     * @return string
     */
    protected function migrationPath(array $files)
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cf-mig-' . uniqid('', true);
        mkdir($directory);

        $this->tempDirectories[] = $directory;

        foreach ($files as $file) {
            copy($this->fixtureFile($file), $directory . DIRECTORY_SEPARATOR . $file);
        }

        return $directory;
    }

    /**
     * Absolute path to a fixture migration file.
     *
     * @param  string  $file
     * @return string
     */
    protected function fixtureFile(string $file)
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Run migrate against the given fixture files.
     *
     * @param  array<int, string>  $files
     * @return string
     */
    protected function migrateFixtures(array $files)
    {
        $path = $this->migrationPath($files);

        $this->artisan('migrate', [
            '--path'     => [$path],
            '--realpath' => true,
            '--force'    => true,
        ])->assertSuccessful();

        return $path;
    }

    /**
     * Insert a user (and optional post) used by the command tests.
     *
     * @param  bool  $withPost
     * @return int
     */
    protected function seedKeptRow(bool $withPost = true)
    {
        $userId = $this->app['db']->table('cf_users')->insertGetId([
            'email'      => 'kept@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app['db']->table('cf_oauth_tokens')->insert([
            'token'      => 'abc123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withPost) {
            $this->app['db']->table('cf_posts')->insert([
                'user_id'    => $userId,
                'title'      => 'original-post',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $userId;
    }

    /**
     * Run fresh:custom against a path of fixture migrations.
     *
     * @param  string  $path
     * @param  array<string, mixed>  $options
     * @return \Illuminate\Testing\PendingCommand
     */
    protected function freshCustom(string $path, array $options = [])
    {
        return $this->artisan('fresh:custom', array_merge([
            '--path'           => [$path],
            '--realpath'       => true,
            '--force'          => true,
            '--no-interaction' => true,
        ], $options));
    }
}
