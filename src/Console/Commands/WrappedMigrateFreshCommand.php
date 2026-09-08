<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Migrations\Migrator;
use Ramadan\CustomFresh\Support\ConfigResolver;
use ReflectionClass;

class WrappedMigrateFreshCommand extends FreshCommand
{
    /**
     * Create a new command instance.
     *
     * Laravel 10's FreshCommand has no constructor arguments. Laravel 11+
     * requires the "migrator" singleton. Pass that binding explicitly —
     * auto-wiring Migrator would fail because MigrationRepositoryInterface
     * is never bound as a class.
     *
     * @param  \Illuminate\Database\Migrations\Migrator|null  $migrator
     */
    public function __construct(?Migrator $migrator = null)
    {
        $constructor = (new ReflectionClass(FreshCommand::class))->getConstructor();

        if ($constructor === null) {
            return;
        }

        if ($constructor->getDeclaringClass()->getName() === FreshCommand::class) {
            $constructor->invokeArgs($this, [$migrator ?? app('migrator')]);

            return;
        }

        $constructor->invokeArgs($this, []);
    }

    /**
     * Execute the console command.
     *
     * When "replace_migrate_fresh" is enabled and the package has anything
     * configured to keep, delegate to "fresh:custom". Otherwise run Laravel's
     * original migrate:fresh.
     *
     * @return int
     */
    public function handle()
    {
        if (! config('custom-fresh.replace_migrate_fresh')) {
            return $this->parentHandle();
        }

        $connection = $this->option('database');
        $config     = new ConfigResolver(is_string($connection) ? $connection : null);

        $hasKeep = ! empty($config->get('always_keep', []))
            || ! empty($config->get('patterns', []))
            || ! empty($config->get('keep_without_migrations', []));

        if (! $hasKeep) {
            return $this->parentHandle();
        }

        return $this->call('fresh:custom', array_filter([
            '--database'    => $connection,
            '--force'       => $this->option('force'),
            '--path'        => $this->option('path'),
            '--realpath'    => $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--seed'        => $this->option('seed'),
            '--seeder'      => $this->option('seeder'),
            '--step'        => $this->option('step'),
            '--drop-views'  => $this->option('drop-views'),
            '--drop-types'  => $this->option('drop-types'),
        ], static fn($value) => $value !== null && $value !== false && $value !== []));
    }

    /**
     * Run Laravel's original migrate:fresh handler.
     *
     * @return int
     */
    protected function parentHandle()
    {
        $result = parent::handle();

        return is_int($result) ? $result : self::SUCCESS;
    }
}
