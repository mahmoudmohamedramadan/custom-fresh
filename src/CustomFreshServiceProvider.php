<?php

namespace Ramadan\CustomFresh;

use Illuminate\Support\ServiceProvider;

class CustomFreshServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            \Ramadan\CustomFresh\Console\Commands\CustomFreshCommand::class,
        ]);
    }
}
