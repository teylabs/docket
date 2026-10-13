<?php

namespace Tey\Docket;

use Illuminate\Support\ServiceProvider;

/** @api */
class DocketServiceProvider extends ServiceProvider
{
    /** @internal */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/docket.php', 'docket');
    }

    /** @internal */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/docket.php' => config_path('docket.php'),
            ], 'docket-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'docket-migrations');
        }
    }
}
