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

        if (! class_exists(FreshCommand::class)) {
            return;
        }

        $wrap = function ($command, $app) {
            return $this->wrapMigrateFreshCommand($command, $app);
        };

        $this->app->extend(FreshCommand::class, $wrap);
        $this->app->extend('command.migrate.fresh', $wrap);
    }

    /**
     * Replace Laravel's migrate:fresh command with the package wrapper.
     *
     * The wrapper must be constructed with the "migrator" singleton. Resolving
     * it through auto-wiring would try to build Migrator from
     * MigrationRepositoryInterface, which Laravel never binds.
     *
     * @param  mixed  $command
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return \Ramadan\CustomFresh\Console\Commands\WrappedMigrateFreshCommand
     */
    protected function wrapMigrateFreshCommand($command, $app)
    {
        if ($command instanceof WrappedMigrateFreshCommand) {
            return $command;
        }

        $wrapped = new WrappedMigrateFreshCommand(
            $app->bound('migrator') ? $app->make('migrator') : null
        );

        $wrapped->setLaravel($app);

        return $wrapped;
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
