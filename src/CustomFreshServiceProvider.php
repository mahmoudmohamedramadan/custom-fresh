<?php

namespace Ramadan\CustomFresh;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\ServiceProvider;
use Ramadan\CustomFresh\Console\Commands\CustomFreshCommand;
use Ramadan\CustomFresh\Console\Commands\WrappedMigrateFreshCommand;

class CustomFreshServiceProvider extends ServiceProvider
{
    /**
     * The absolute path to the bundled config file.
     *
     * @var string
     */
    protected string $configPath = __DIR__ . '/../config/custom-fresh.php';

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom($this->configPath, 'custom-fresh');

        $this->commands([
            CustomFreshCommand::class,
        ]);

        if (class_exists(FreshCommand::class)) {
            $this->app->extend(FreshCommand::class, function ($command, $app) {
                return $app->make(WrappedMigrateFreshCommand::class);
            });

            if ($this->app->bound('command.migrate.fresh')) {
                $this->app->extend('command.migrate.fresh', function ($command, $app) {
                    return $app->make(WrappedMigrateFreshCommand::class);
                });
            }
        }
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath => $this->app->configPath('custom-fresh.php'),
            ], 'custom-fresh-config');
        }
    }
}
