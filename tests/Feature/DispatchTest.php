<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Events\RunDispatched;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Exceptions\ResultNotAvailable;
use Tey\Docket\Facades\Docket;
use Tey\Docket\PendingRun;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\EncryptedWork;
use Tey\Docket\Tests\Fixtures\KeyedWork;
use Tey\Docket\Tests\Fixtures\PlainWork;
use Tey\Docket\Tests\Fixtures\QueuedWork;
use Tey\Docket\Tests\Fixtures\RecordWork;
use Tey\Docket\Tests\Fixtures\Upload;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

beforeEach(function () {
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false, 'queue.batching.database' => 'testing', 'queue.failed.driver' => null]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('job_batches', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    Schema::create('work_results', fn (Blueprint $table) => $table->string('label'));
    Schema::create('uploads', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
});

it('queues a native job and records its final status on a real worker', function () {
    $events = [];
    Event::listen(RunDispatched::class, function ($event) use (&$events) {
        $events[] = $event;
    });
    Event::listen(RunFinished::class, function ($event) use (&$events) {
        $events[] = $event;
    });
    $ticket = Docket::dispatch(new RecordWork('first'));

    expect($ticket->name)->toBe(RecordWork::class)
        ->and($ticket->status)->toBe(RunStatus::Queued)
        ->and($ticket->revision)->toBe(1)
        ->and($ticket->run()->getAttribute('stale_after'))->toBe(75)
        ->and(DB::table('work_results')->count())->toBe(0);

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    expect($payload['data']['commandName'])->toBe(RecordWork::class)
        ->and($payload['timeout'])->toBe(75)
        ->and($payload['maxTries'])->toBe(2);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

    expect(DB::table('work_results')->pluck('label')->all())->toBe(['first'])
        ->and($ticket->status)->toBe(RunStatus::Queued)
        ->and($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and($ticket->run()->revision)->toBe(3)
        ->and($ticket->run()->finished_at)->not->toBeNull()
        ->and($ticket->run()->expires_at)->toEqual($ticket->run()->finished_at->addDay())
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(RunDispatched::class)
        ->and($events[1])->toBeInstanceOf(RunFinished::class)
        ->and($events[0]->id)->toBe((new RunDispatched($ticket->run()))->id)
        ->and($events[1]->id)->toBe((new RunFinished($ticket->run()))->id)
        ->and($events[1]->id)->not->toBe($events[0]->id);
});

it('persists the subject, initiator and every dispatch option without leaking defaults', function () {
    $subject = Upload::create();
    $user = User::create();
    actingAs($user);
    $expiry = now()->addHours(3)->startOfSecond();
    $pending = Docket::job(new RecordWork)->name('rows.import')->about($subject)->total(500)->staleAfter(300)->expiresAt($expiry);
    $run = $pending->dispatch()->run();

    expect($pending->name)->toBe('rows.import')
        ->and($pending->subject->is($subject))->toBeTrue()
        ->and($pending->initiator->is($user))->toBeTrue()
        ->and($pending->jobs)->toHaveCount(1)
        ->and($run->subject->is($subject))->toBeTrue()
        ->and($run->initiator->is($user))->toBeTrue()
        ->and($run->total)->toBe(500)
        ->and($run->getAttribute('stale_after'))->toBe(300)
        ->and($run->expires_at)->toEqual($expiry);

    $other = Docket::job(new RecordWork)->by(null)->dispatch()->run();
    expect($other->initiator)->toBeNull()->and($other->subject)->toBeNull()->and($other->total)->toBeNull();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($run->fresh()->expires_at)->toEqual($expiry);
});

it('runs jobs and queued closures synchronously and names closures by default', function () {
    $ticket = Docket::dispatchSync(new RecordWork('sync'));
    $closure = Docket::job(function (Run $run) {
        $run->logs()->create(['code' => 'closure.received']);
    })->dispatchSync();
    $plain = Docket::dispatch(new PlainWork);

    expect($ticket->status)->toBe(RunStatus::Succeeded)
        ->and($closure->name)->toBe('closure')
        ->and($closure->status)->toBe(RunStatus::Succeeded)
        ->and($closure->run()->logs()->pluck('code')->all())->toBe(['closure.received'])
        ->and($plain->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['sync', 'plain'])
        ->and(Queue::size())->toBe(0);
});

it('serializes a queued closure and supplies its stored run on the worker', function () {
    $ticket = Docket::job(function (Run $run) {
        $run->logs()->create(['code' => 'closure.queued']);
    })->name('cache.warm')->dispatch();
    expect($ticket->run()->logs()->count())->toBe(0);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['closure.queued']);
});

it('tracks queued jobs without the Queueable trait both asynchronously and synchronously', function () {
    $queued = Docket::dispatch(new QueuedWork);
    expect($queued->status)->toBe(RunStatus::Queued);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    $sync = Docket::dispatchSync(new QueuedWork);
    expect($queued->run()->status)->toBe(RunStatus::Succeeded)
        ->and($sync->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['plain', 'plain'])
        ->and(Queue::size())->toBe(0);
});

it('preserves native job encryption and finishes duplicate deliveries only once', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Event::fake([RunFinished::class]);
    $ticket = Docket::job(new EncryptedWork('private-encrypted-input'))
        ->finally(function (Run $run) {
            $run->logs()->create(['code' => 'finished']);
        })->dispatch();
    $queued = DB::table('jobs')->first();
    expect($queued->payload)->not->toContain('private-encrypted-input');
    DB::table('jobs')->insert([
        'queue' => $queued->queue, 'payload' => $queued->payload, 'attempts' => 0,
        'reserved_at' => null, 'available_at' => $queued->available_at, 'created_at' => $queued->created_at,
    ]);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['private-encrypted-input', 'private-encrypted-input'])
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['finished']);
    Event::assertDispatchedTimes(RunFinished::class, 1);
});

it('runs a native chain in order and only finishes after its last member', function () {
    $ticket = Docket::chain([new RecordWork('build'), new RecordWork('upload')])->dispatch();
    expect($ticket->name)->toBe('chain');
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Running)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['build']);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['build', 'upload']);

    $sync = Docket::chain([new RecordWork('sync.build'), new RecordWork('sync.upload')])->dispatchSync();
    expect($sync->status)->toBe(RunStatus::Succeeded)->and(Queue::size())->toBe(0);
});

it('dispatches an iterable native batch containing a chain and finishes once', function () {
    Event::fake([RunFinished::class]);
    $ticket = Docket::batch(collect([new RecordWork('a'), [new RecordWork('b'), new RecordWork('c')]]))->dispatch();
    expect($ticket->name)->toBe('batch')->and(Queue::size())->toBe(2);
    $batch = Bus::findBatch(DB::table('job_batches')->value('id'));
    expect($batch->totalJobs)->toBe(3);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->finished())->toBeFalse();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['a', 'b', 'c']);
    Event::assertDispatchedTimes(RunFinished::class, 1);
});

it('waits for the outer commit and records rollback without queuing work', function () {
    Event::fake([RunDispatched::class, RunFinished::class]);
    DB::beginTransaction();
    $commit = Docket::dispatch(new RecordWork('commit'));
    DB::beginTransaction();
    $rollback = Docket::dispatch(new RecordWork('rollback'));
    DB::rollBack();

    expect(Queue::size())->toBe(0)
        ->and($rollback->run()->status)->toBe(RunStatus::Failed)
        ->and($rollback->run()->error)->toBe(['code' => 'run.rolled_back', 'params' => []]);
    Event::assertNotDispatched(RunDispatched::class);
    Event::assertDispatchedTimes(RunFinished::class, 1);
    DB::commit();

    expect(Queue::size())->toBe(1)->and($commit->run()->status)->toBe(RunStatus::Queued);
    Event::assertDispatchedTimes(RunDispatched::class, 1);
    Event::assertDispatchedTimes(RunFinished::class, 1);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('work_results')->pluck('label')->all())->toBe(['commit']);

    DB::beginTransaction();
    DB::beginTransaction();
    $nested = Docket::dispatch(new RecordWork('nested'));
    DB::commit();
    DB::rollBack();
    expect(Queue::size())->toBe(0)->and($nested->run()->error['code'])->toBe('run.rolled_back');
    Event::assertDispatchedTimes(RunFinished::class, 3);
});

it('reuses only unfinished runs with the same name and subject for unique dispatches', function () {
    $first = Upload::create();
    $second = Upload::create();
    $ticket = Docket::job(new RecordWork)->name('import')->about($first)->unique()->dispatch();
    $same = Docket::job(new RecordWork)->name('import')->about($first)->unique()->dispatch();
    $different = Docket::job(new RecordWork)->name('import')->about($second)->unique()->dispatch();
    $name = Docket::job(new RecordWork)->name('other')->about($first)->unique()->dispatch();
    expect($same->id)->toBe($ticket->id)->and($different->id)->not->toBe($ticket->id)
        ->and($name->id)->not->toBe($ticket->id)->and(Queue::size())->toBe(3);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect(Docket::job(new RecordWork)->name('import')->about($first)->unique()->dispatch()->id)->not->toBe($ticket->id);
});

it('reuses idempotency keys through completion until expiry and ignores null keys', function () {
    freezeTime();
    $ticket = Docket::job(new RecordWork)->name('import')->idempotencyKey('request:1')->expiresAt(now()->addMinute())->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $same = Docket::job(new RecordWork)->name('import')->idempotencyKey('request:1')->about(Upload::create())->dispatch();
    expect($same->id)->toBe($ticket->id)->and($same->status)->toBe(RunStatus::Succeeded)->and(Queue::size())->toBe(0);
    expect(Docket::job(new RecordWork)->name('other')->idempotencyKey('request:1')->dispatch()->id)->not->toBe($ticket->id);
    travel(1)->minutes();
    expect(Docket::job(new RecordWork)->name('import')->idempotencyKey('request:1')->dispatch()->id)->not->toBe($ticket->id);
    $first = Docket::job(new RecordWork)->idempotencyKey(null)->dispatch();
    expect(Docket::job(new RecordWork)->idempotencyKey(null)->dispatch()->id)->not->toBe($first->id);
});

it('keeps releases and retriable exceptions running until the job really completes', function (string $behaviour) {
    $ticket = Docket::job(new RecordWork('attempt', $behaviour))->dispatch();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Running)->and($ticket->run()->finished_at)->toBeNull();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->count())->toBe(2);
})->with(['release', 'retry']);

it('fires persisted success and failure callbacks only on the final outcome', function (string $behaviour) {
    Event::fake([RunFinished::class]);
    $ticket = Docket::job(new RecordWork('attempt', $behaviour))
        ->then(function (Run $run) {
            $run->logs()->create(['code' => 'callback.then']);
        })
        ->catch(function (Run $run) {
            $run->logs()->create(['code' => 'callback.catch']);
        })
        ->finally(function (Run $run) {
            $run->logs()->create(['code' => 'callback.finally']);
        })
        ->dispatch();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->logs()->count())->toBe(0);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe($behaviour === 'fail' ? RunStatus::Failed : RunStatus::Succeeded)
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe([$behaviour === 'fail' ? 'callback.catch' : 'callback.then', 'callback.finally']);
    Event::assertDispatchedTimes(RunFinished::class, 1);
    if ($behaviour === 'fail') {
        expect($ticket->run()->error)->toBe(['code' => 'run.failed', 'params' => []])
            ->and($ticket->run()->toJson())->not->toContain('private customer content');
    }
})->with(['fail', 'retry']);

it('records synchronous exceptions and rethrows them unchanged', function () {
    expect(fn () => Docket::dispatchSync(new RecordWork('sync', 'fail')))->toThrow(RuntimeException::class, 'private customer content');
    expect(Run::first()->status)->toBe(RunStatus::Failed)
        ->and(Run::first()->error)->toBe(['code' => 'run.failed', 'params' => []]);
});

it('starts independent options without mutable facade state', function () {
    $subject = Upload::create();
    expect(Docket::about($subject)->subject->is($subject))->toBeTrue()
        ->and(Docket::name('first')->name)->toBe('first')
        ->and(Docket::by(null)->initiator)->toBeNull()
        ->and(Docket::job(new RecordWork)->subject)->toBeNull()
        ->and(Docket::job(new RecordWork)->name)->toBe(RecordWork::class);
    foreach (['unique' => [], 'idempotencyKey' => [null], 'total' => [5], 'staleAfter' => [10], 'expiresAt' => [now()], 'then' => [fn () => null], 'catch' => [fn () => null], 'finally' => [fn () => null]] as $method => $arguments) {
        expect(Docket::{$method}(...$arguments))->toBeInstanceOf(PendingRun::class);
    }
    expect(fn () => Docket::batch([])->dispatch())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Docket::batch([[]])->dispatch())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Docket::chain([])->dispatch())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Docket::total(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Docket::staleAfter(0))->toThrow(InvalidArgumentException::class);
});

it('uses configured staleness and counts keyed batch members with chains as one unit', function () {
    config(['docket.stale_after' => 123]);
    $closure = Docket::job(fn (Run $run) => null)->dispatch();
    $batch = Docket::batch([
        new KeyedWork('unit:1'), [new KeyedWork('unit:2'), new KeyedWork('unit:3')],
        new KeyedWork('unit:1'), new RecordWork('unkeyed'),
    ])->dispatch();
    expect($closure->run()->getAttribute('stale_after'))->toBe(123)
        ->and($batch->run()->total)->toBe(2)
        ->and(Docket::batch([new KeyedWork('unit:4')])->total(0)->dispatch()->run()->total)->toBe(0);
});

it('reuses an unfinished run even when the original dispatch did not request uniqueness', function () {
    $ticket = Docket::job(new RecordWork)->name('import')->dispatch();
    expect(Docket::job(new RecordWork)->name('import')->unique()->dispatch()->id)->toBe($ticket->id)
        ->and(Queue::size())->toBe(1);
});

it('finishes failed chains and batches once without starting their remaining work', function (string $source) {
    Event::fake([RunFinished::class]);
    $job = new RecordWork('failed', 'fail');
    $job->tries = 1;
    $ticket = Docket::{$source}([$job, new RecordWork('must.not.run')])
        ->catch(function (Run $run) {
            $run->logs()->create(['code' => 'caught']);
        })
        ->finally(function (Run $run) {
            $run->logs()->create(['code' => 'finished']);
        })
        ->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Failed)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['failed'])
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['caught', 'finished']);
    Event::assertDispatchedTimes(RunFinished::class, 1);
})->with(['chain', 'batch']);

it('runs finally but not then or catch on a partial finish', function () {
    $ticket = Docket::job(function (Run $run) {
        $run->update(['skipped_count' => 1]);
    })
        ->then(function (Run $run) {
            $run->logs()->create(['code' => 'then']);
        })
        ->catch(function (Run $run) {
            $run->logs()->create(['code' => 'catch']);
        })
        ->finally(function (Run $run) {
            $run->logs()->create(['code' => 'finally']);
        })
        ->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Partial)
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['finally']);
});

it('records enqueue failures without leaking the exception into the ticket', function () {
    $job = (new RecordWork)->onConnection('missing');
    expect(fn () => Docket::dispatch($job))->toThrow(InvalidArgumentException::class);
    expect(Run::first()->status)->toBe(RunStatus::Failed)
        ->and(Run::first()->error)->toBe(['code' => 'run.failed', 'params' => []]);
});

it('defers sync work until commit and isolates already committed savepoints from later rollbacks', function () {
    DB::beginTransaction();
    DB::beginTransaction();
    $first = Docket::job(new RecordWork('first'))->dispatchSync();
    DB::commit();
    DB::beginTransaction();
    $second = Docket::job(new RecordWork('second'))->dispatchSync();
    DB::rollBack();
    expect($first->run()->status)->toBe(RunStatus::Queued)
        ->and($second->run()->error['code'])->toBe('run.rolled_back')
        ->and(DB::table('work_results')->count())->toBe(0);
    DB::commit();
    expect($first->run()->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['first']);
});

it('runs synchronous batches on a separate docket database', function () {
    config(['database.connections.records' => ['driver' => 'sqlite', 'database' => ':memory:'], 'docket.connection' => 'records']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_13_000000_create_docket_tables.php';
    $migration->up();
    $ticket = Docket::batch([new RecordWork('a'), [new RecordWork('b'), new RecordWork('c')]])->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Succeeded)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['a', 'b', 'c'])
        ->and(Queue::size())->toBe(0);
    config(['docket.connection' => null]);
});

it('reports callback exceptions and still invokes finally without changing the outcome', function () {
    Exceptions::fake([RuntimeException::class]);
    $ticket = Docket::job(new RecordWork)
        ->then(function (Run $run) {
            throw new RuntimeException('callback failed');
        })
        ->finally(function (Run $run) {
            $run->logs()->create(['code' => 'finally']);
        })->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Succeeded)
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['finally']);
    Exceptions::assertReported(RuntimeException::class);
});

it('rejects an initiator that cannot be stored as an Eloquent morph', function () {
    expect(fn () => Docket::job(new RecordWork)->by(new GenericUser(['id' => 1]))->dispatch())
        ->toThrow(InvalidArgumentException::class, 'A run initiator must be an Eloquent model.');
    expect(Run::count())->toBe(0)->and(Queue::size())->toBe(0);
});

it('sets the current run on a real worker and clears it before the next job', function () {
    $ticket = Docket::job(function (Run $run) {
        DB::table('work_results')->insert(['label' => Docket::current()->id ?? 'missing']);
        Docket::run('nested', fn () => null);
        DB::table('work_results')->insert(['label' => Docket::current()->id ?? 'missing']);
    })->dispatch();
    expect(fn () => $ticket->result())->toThrow(ResultNotAvailable::class);
    dispatch(function () {
        DB::table('work_results')->insert(['label' => Docket::current() === null ? 'clear' : 'leaked']);
    });
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('work_results')->pluck('label')->all())->toBe([$ticket->id, $ticket->id, 'clear'])
        ->and(Docket::current())->toBeNull();
});
