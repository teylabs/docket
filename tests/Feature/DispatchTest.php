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
use Tey\Docket\Tests\Fixtures\PlainReportingWork;
use Tey\Docket\Tests\Fixtures\PlainWork;
use Tey\Docket\Tests\Fixtures\QueuedWork;
use Tey\Docket\Tests\Fixtures\RecordWork;
use Tey\Docket\Tests\Fixtures\ReportingWork;
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

it('attaches docketable only to dispatched copies and settles keyed jobs on a real worker', function () {
    $job = new ReportingWork('unit:1');
    expect($job->docket())->toBeNull();
    $ticket = Docket::batch([$job, new ReportingWork('unit:2', 'skip')])->dispatch();
    expect($job->docket())->toBeNull()->and($ticket->run()->items()->pending()->count())->toBe(2);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $run = $ticket->run();
    expect($run->status)->toBe(RunStatus::Partial)->and($run->done)->toBe(2)
        ->and($run->succeeded_count)->toBe(1)->and($run->skipped_count)->toBe(1)
        ->and($run->items()->where('key', 'unit:2')->first()->code)->toBe('unit.locked')
        ->and(Docket::current())->toBeNull();
});

it('fails only the keyed item on its final attempt including a chain keyed by its first job', function (bool $chain) {
    $failed = new ReportingWork('bad', 'fail');
    $ticket = Docket::batch([
        $chain ? [$failed, new ReportingWork('must.not.run')] : $failed,
        new ReportingWork('good'),
    ])->dispatch();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->failed_count)->toBe(0)->and($ticket->run()->finished())->toBeFalse();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $run = $ticket->run();
    expect($run->status)->toBe(RunStatus::Partial)->and($run->done)->toBe(2)
        ->and($run->failed_count)->toBe(1)->and($run->succeeded_count)->toBe(1)
        ->and($run->items()->failed()->first()->code)->toBe('run.job_failed')
        ->and($run->toJson())->not->toContain('private customer content')
        ->and(DB::table('work_results')->pluck('label')->all())->not->toContain('must.not.run');
})->with([true, false]);

it('settles a batch chain as one item only when its final member completes', function () {
    $ticket = Docket::batch([[new ReportingWork('first'), new ReportingWork('last')]])->dispatch();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->done)->toBe(0)->and($ticket->run()->finished())->toBeFalse();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->done)->toBe(1)->and($ticket->run()->items()->pluck('key')->all())->toBe(['first'])
        ->and($ticket->run()->status)->toBe(RunStatus::Succeeded);
});

it('ends queued fail immediately without retrying or running the rest of a chain', function () {
    $ticket = Docket::chain([new ReportingWork('stop', 'stop'), new ReportingWork('must.not.run')])->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Failed)
        ->and($ticket->run()->error)->toBe(['code' => 'file.unsupported', 'params' => ['id' => 42]])
        ->and($ticket->run()->logs()->count())->toBe(1)->and(DB::table('work_results')->count())->toBe(0)
        ->and(Queue::size())->toBe(0)->and(Docket::current())->toBeNull();
});

it('supports docketable jobs without Queueable and keeps plain dispatch untracked', function (bool $sync) {
    $pending = Docket::job(new PlainReportingWork);
    $ticket = $sync ? $pending->dispatchSync() : $pending->dispatch();
    Bus::dispatch(new PlainReportingWork);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->error['code'])->toBe('file.unsupported')
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['plain']);
})->with([true, false]);

it('stops a cancelled queued run before handling and preserves completed item references', function () {
    $ticket = Docket::batch([new ReportingWork('next')])->dispatch();
    $run = $ticket->run();
    $run->itemSucceeded('previous', result: 'file:1');
    $run->update(['cancel_requested' => true]);
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run->results()->all())->toBe(['previous' => 'file:1'])
        ->and($run->items()->pending()->count())->toBe(1)->and(DB::table('work_results')->count())->toBe(0);
});

it('requests cancellation idempotently and cancels the native batch before the next safe point', function () {
    $run = Docket::batch([new ReportingWork('first'), new ReportingWork('next')])->dispatch()->run();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    $stale = Run::findOrFail($run->id);
    expect($run->cancel())->toBe($run);
    $revision = $run->revision;
    $stale->cancel();
    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->cancelRequested())->toBeTrue()
        ->and($run->revision)->toBe($revision)
        ->and(Bus::findBatch($run->getAttribute('batch_id'))->cancelled())->toBeTrue();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($run->refresh()->cancelled())->toBeTrue()
        ->and($run->cancelRequested())->toBeFalse()
        ->and($run->succeeded_count)->toBe(1)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['first']);
    $revision = $run->revision;
    $run->cancel();
    expect($run->revision)->toBe($revision);
});

it('retries native batches and chains with fresh identities and skips inherited successes', function (string $source) {
    $pending = $source === 'batch'
        ? Docket::batch([new ReportingWork('first'), [new ReportingWork('next'), new RecordWork('last')]])
        : Docket::chain([new ReportingWork('first'), new RecordWork('last')]);
    $run = $pending->name('retry.work')->unique()->idempotencyKey('original')->dispatch()->run();
    if ($source === 'batch') {
        artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    }
    $run->checkpoint(['page' => 2]);
    $run->cancel();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $retry = $run->refresh()->retry()->dispatch()->run();
    expect($retry->id)->not->toBe($run->id)->and($retry->token)->not->toBe($run->token)
        ->and($retry->retry_of_run_id)->toBe($run->id)->and($retry->parent_run_id)->toBeNull()
        ->and($retry->checkpoint())->toBe(['page' => 2])->and($retry->name)->toBe('retry.work')
        ->and($retry->getAttribute('unique_key'))->toBeNull()->and($retry->getAttribute('idempotency_key'))->toBeNull();
    $run->update(['expires_at' => now()]);
    artisan('docket:prune')->assertSuccessful();
    expect(Run::find($run->id))->toBeNull();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($retry->refresh()->succeeded())->toBeTrue()
        ->and(DB::table('work_results')->pluck('label')->all())->toBe($source === 'batch' ? ['first', 'next', 'last'] : ['first', 'last']);
})->with(['batch', 'chain']);

it('replays an inline closure on the queue with its checkpoint and no repeated successful side effects', function () {
    $run = Docket::run('pages', static function (Run $run) {
        foreach ($run->track(['one', 'two', 'three']) as $key) {
            DB::table('work_results')->insert(['label' => $key]);
            $run->itemSucceeded($key, result: 'file:'.$key, count: 2);
            if ($run->retry_of_run_id === null) {
                $run->checkpoint(42)->cancel()->throwIfCancelled();
            }
        }
        $run->log('checkpoint.inherited', ['position' => $run->checkpoint()]);
    })->run();
    $pending = $run->retry();
    expect($pending)->toBeInstanceOf(PendingRun::class)->and(Run::count())->toBe(1);
    $retry = Docket::run('outer', fn () => $pending->dispatch())->result()->run();
    expect($retry->parent_run_id)->toBeNull()->and($retry->root_run_id)->toBeNull();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($retry->refresh()->succeeded())->toBeTrue()->and($retry->done)->toBe(6)
        ->and($retry->logs()->sole()->params)->toBe(['position' => 42])
        ->and($retry->results()->all())->toBe(['one' => 'file:one', 'two' => 'file:two', 'three' => 'file:three'])
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['one', 'two', 'three']);
    expect($retry->toJson())->not->toContain('replay', 'checkpoint', 'batch_id', 'last_report_at');
});

it('rejects retrying unfinished and succeeded runs using their persisted status', function (RunStatus $status) {
    $run = Docket::dispatch(new RecordWork)->run();
    $run->update(['status' => $status]);
    expect(fn () => $run->retry())->toThrow(LogicException::class)
        ->and(Run::count())->toBe(1);
})->with([RunStatus::Queued, RunStatus::Running, RunStatus::Succeeded]);

it('replays failed work and preserves subject initiator and stale interval without old expiry', function () {
    $subject = Upload::create();
    $user = User::create();
    $run = Docket::job(new ReportingWork('bad', 'stop'))->about($subject)->by($user)
        ->catch(static fn (Run $run) => $run->logs()->create(['code' => 'callback.catch']))
        ->staleAfter(12)->expiresAt(now()->addHour())->dispatchSync()->run();
    $retry = $run->retry()->dispatchSync()->run();
    expect($retry->failed())->toBeTrue()->and($retry->error)->toBe($run->error)
        ->and($retry->subject->is($subject))->toBeTrue()->and($retry->initiator->is($user))->toBeTrue()
        ->and($retry->stale_after)->toBe(12)->and($retry->expires_at)->toEqual($retry->finished_at->copy()->addDay())
        ->and($retry->logs()->where('code', 'callback.catch')->count())->toBe(1);
});

it('inherits all successful keys across repeated partial retries', function () {
    $run = Docket::batch([new ReportingWork('good'), new ReportingWork('skip', 'skip')])->dispatch()->run();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $retry = $run->retry()->dispatch()->run();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $next = $retry->retry()->dispatch()->run();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    $next->refresh();
    expect($next->partial())->toBeTrue()->and($next->succeeded_count)->toBe(1)->and($next->skipped_count)->toBe(1)
        ->and($next->retry_of_run_id)->toBe($retry->id)
        ->and(DB::table('work_results')->where('label', 'good')->count())->toBe(1)
        ->and(DB::table('work_results')->where('label', 'skip')->count())->toBe(3);
});

it('counts duplicate keyed deliveries once and preserves successes after an unkeyed failure', function () {
    $failed = new RecordWork('bad', 'fail');
    $failed->tries = 1;
    $ticket = Docket::batch([new ReportingWork('good'), new ReportingWork('good'), $failed, new ReportingWork('must.not.run')])->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Failed)
        ->and($ticket->run()->done)->toBe(1)->and($ticket->run()->succeeded_count)->toBe(1)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['good', 'bad']);
});

it('keeps keyed release attempts pending until they really complete', function () {
    $ticket = Docket::job(new ReportingWork('released', 'release'))->dispatch();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->done)->toBe(0)->and($ticket->run()->items()->pending()->count())->toBe(1);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->done)->toBe(1)->and($ticket->run()->succeeded())->toBeTrue();
});

it('keeps inline runs open for added work and skips already succeeded keys', function () {
    Event::fake([RunFinished::class]);
    $ticket = Docket::run(function (Run $run) {
        $run->itemSucceeded('done');
        $run->add(collect([new ReportingWork('done'), new ReportingWork('next', 'add')]));

        return 'inline-result';
    });
    expect($ticket->status)->toBe(RunStatus::Running)->and($ticket->result())->toBe('inline-result')
        ->and(Queue::size())->toBe(1);
    Event::assertNotDispatched(RunFinished::class);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->finished())->toBeFalse();
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Succeeded)->and($ticket->run()->done)->toBe(3)
        ->and(Run::count())->toBe(1)->and(DB::table('work_results')->pluck('label')->all())->toBe(['next', 'added']);
    Event::assertDispatchedTimes(RunFinished::class, 1);
});

it('dispatches added work synchronously when the run used dispatchSync', function () {
    $ticket = Docket::job(new ReportingWork('first', 'add'))->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Succeeded)->and($ticket->run()->done)->toBe(2)
        ->and(Queue::size())->toBe(0)->and(Run::count())->toBe(1)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['added', 'first']);
});

it('keeps synchronous keyed failures local to their item and continues the batch', function () {
    // Native sync batches hold a write transaction; SQLite needs a separate reporting database.
    config(['database.connections.records' => ['driver' => 'sqlite', 'database' => ':memory:'], 'docket.connection' => 'records']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_13_000000_create_docket_tables.php';
    $migration->up();
    Exceptions::fake([RuntimeException::class]);
    $ticket = Docket::batch([[new ReportingWork('bad', 'fail'), new ReportingWork('must.not.run')], new ReportingWork('good')])->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Partial)->and($ticket->run()->done)->toBe(2)
        ->and($ticket->run()->failed_count)->toBe(1)->and($ticket->run()->succeeded_count)->toBe(1)
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['bad', 'good']);
    Exceptions::assertReported(RuntimeException::class);
});

it('defers added work until the application commits and records a rollback', function (bool $commit) {
    DB::beginTransaction();
    $ticket = Docket::run(fn (Run $run) => $run->add(new ReportingWork('added')));
    expect(Queue::size())->toBe(0)->and($ticket->status)->toBe(RunStatus::Running);
    if ($commit) {
        DB::commit();
        expect(Queue::size())->toBe(1)->and($ticket->run()->finished())->toBeFalse();
        artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
        expect($ticket->run()->status)->toBe(RunStatus::Succeeded);
    } else {
        DB::rollBack();
        expect(Queue::size())->toBe(0)->and($ticket->run()->error['code'])->toBe('run.rolled_back');
    }
})->with([true, false]);
