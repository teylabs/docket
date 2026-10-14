<?php

namespace Tey\Docket;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        if (! $this->app->configurationIsCached()) {
            $defaults = require __DIR__.'/../config/docket.php';
            $this->app->make('config')->set('docket.routes', array_replace(
                $defaults['routes'], $this->app->make('config')->array('docket.routes'),
            ));
        }

        $this->app->scoped(CurrentRun::class);
        $this->app->scoped(Tracking::class);
        $this->app->scoped(Transactions::class, fn ($app) => new Transactions($app->make('db.transactions')));
    }

    /** @internal */
    public function boot(): void
    {
        if (Gate::getPolicyFor(Run::class) === null) {
            Gate::policy(Run::class, Policies\RunPolicy::class);
        }

        if (config()->boolean('docket.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/docket.php');
        }

        Queue::createPayloadUsing(static fn ($connection, $queue, array $payload) => app(Tracking::class)->payload($payload));

        Event::listen(JobProcessing::class, [Tracking::class, 'processing']);
        Event::listen(JobProcessing::class, [CurrentRun::class, 'processing']);
        Event::listen(JobProcessed::class, [CurrentRun::class, 'finished']);
        Event::listen(JobFailed::class, [CurrentRun::class, 'finished']);
        Event::listen(JobExceptionOccurred::class, [CurrentRun::class, 'finished']);
        Event::listen(JobProcessed::class, [Tracking::class, 'processed']);
        Event::listen(JobFailed::class, [Tracking::class, 'failed']);
        Event::listen(TransactionCommitted::class, [Transactions::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [Transactions::class, 'rolledBack']);

        foreach (['RequestReceived', 'RequestTerminated', 'TaskReceived', 'TaskTerminated', 'TickReceived', 'TickTerminated'] as $event) {
            Event::listen('Laravel\\Octane\\Events\\'.$event, OctaneBoundary::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([Console\Prune::class]);

            $this->publishes([
                __DIR__.'/../config/docket.php' => config_path('docket.php'),
            ], 'docket-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'docket-migrations');
        }
    }
}
