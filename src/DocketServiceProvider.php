<?php

namespace Tey\Docket;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Tey\Docket\Dispatch\Tracking;
use Tey\Docket\Dispatch\Transactions;

/** @api */
class DocketServiceProvider extends ServiceProvider
{
    /** @internal */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/docket.php', 'docket');
        $this->app->scoped(Tracking::class);
        $this->app->scoped(Transactions::class, fn ($app) => new Transactions($app->make('db.transactions')));
    }

    /** @internal */
    public function boot(): void
    {
        Queue::createPayloadUsing(static fn ($connection, $queue, array $payload) => app(Tracking::class)->payload($payload));

        Event::listen(JobProcessing::class, [Tracking::class, 'processing']);
        Event::listen(JobProcessed::class, [Tracking::class, 'processed']);
        Event::listen(JobFailed::class, [Tracking::class, 'failed']);
        Event::listen(TransactionCommitted::class, [Transactions::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [Transactions::class, 'rolledBack']);

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
