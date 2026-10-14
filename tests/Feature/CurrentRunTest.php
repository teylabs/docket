<?php

use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tey\Docket\CurrentRun;
use Tey\Docket\Exceptions\ResultNotAvailable;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;

it('restores the caller after tracked and untracked synchronous queue work', function (bool $throws) {
    Docket::run('outer', function (Run $outer) use ($throws) {
        try {
            $ticket = Docket::job(function (Run $run) use ($throws) {
                expect(Docket::current()?->id)->toBe($run->id);
                Docket::run('nested', function (Run $nested) {
                    expect(Docket::current())->toBe($nested);
                });
                expect(Docket::current()?->id)->toBe($run->id);
                if ($throws) {
                    throw new RuntimeException('private');
                }
            })->dispatchSync();
            expect(fn () => $ticket->result())->toThrow(ResultNotAvailable::class);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('private');
        }
        expect(Docket::current())->toBe($outer);

        try {
            Bus::dispatchSync(CallQueuedClosure::create(function () use ($throws) {
                expect(Docket::current())->toBeNull();
                if ($throws) {
                    throw new RuntimeException('private');
                }
            }));
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('private');
        }
        expect(Docket::current())->toBe($outer);
    });
    expect(Docket::current())->toBeNull();
})->with([true, false]);

it('clears the current run on every worker exit and before untracked jobs', function (string $boundary) {
    $run = Run::create(['name' => 'queued']);
    $queue = new DatabaseQueue(DB::connection(), 'jobs');
    $record = new DatabaseJobRecord((object) ['payload' => json_encode(['docket' => ['id' => $run->id, 'finishes' => false, 'batch_id' => null, 'item_key' => null, 'work_id' => 'test-work']])]);
    $job = new DatabaseJob(app(), $queue, $record, 'database', 'default');
    Event::dispatch(new JobProcessing('database', $job));
    expect(Docket::current()?->id)->toBe($run->id);
    $event = match ($boundary) {
        'processed' => new JobProcessed('database', $job),
        'failed' => new JobFailed('database', $job, new RuntimeException),
        'exception' => new JobExceptionOccurred('database', $job, new RuntimeException),
        default => throw new InvalidArgumentException($boundary),
    };
    Event::dispatch($event);
    expect(Docket::current())->toBeNull();
    $plain = new DatabaseJob(app(), $queue, new DatabaseJobRecord((object) ['payload' => '{}']), 'database', 'default');
    Event::dispatch(new JobProcessing('database', $plain));
    expect(Docket::current())->toBeNull();
})->with(['processed', 'failed', 'exception']);

it('resolves the current scoped stack rather than caching it on the facade', function () {
    Docket::run(function () {
        app()->forgetScopedInstances();
        expect(Docket::current())->toBeNull();
    });
    expect(Docket::current())->toBeNull();
});

it('sets and unwinds the current run for native synchronous handlers', function () {
    $ticket = Docket::dispatchSync(new class
    {
        public function handle(): void
        {
            Docket::current()?->logs()->create(['code' => 'handler.current']);
        }
    });
    expect($ticket->run()->logs()->pluck('code')->all())->toBe(['handler.current'])
        ->and(Docket::current())->toBeNull();
});

it('resets the event sandbox at each Octane request task and tick boundary', function (string $boundary) {
    $sandbox = clone app();
    $sandbox->instance(CurrentRun::class, new CurrentRun);
    $stack = $sandbox->make(CurrentRun::class);
    $stack->during(Run::create(['name' => 'sandbox']), function () use ($sandbox, $stack, $boundary) {
        Docket::run('base', function (Run $base) use ($sandbox, $stack, $boundary) {
            Event::dispatch('Laravel\\Octane\\Events\\'.$boundary, [(object) ['sandbox' => $sandbox]]);
            expect($stack->current())->toBeNull()->and(Docket::current())->toBe($base);
        });
    });
})->with(['RequestReceived', 'RequestTerminated', 'TaskReceived', 'TaskTerminated', 'TickReceived', 'TickTerminated']);
