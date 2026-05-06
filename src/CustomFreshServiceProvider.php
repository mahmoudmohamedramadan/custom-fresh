<?php

namespace Ramadan\CustomFresh;

use Illuminate\Support\ServiceProvider;

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
            \Ramadan\CustomFresh\Console\Commands\CustomFreshCommand::class,
        ]);
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
