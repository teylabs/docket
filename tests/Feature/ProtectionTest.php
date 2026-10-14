<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Events\LoopDetected as LoopDetectedEvent;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Exceptions\DepthExceeded;
use Tey\Docket\Exceptions\LoopDetected;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\KeyedWork;
use Tey\Docket\Tests\Fixtures\RecordWork;
use Tey\Docket\Tests\Fixtures\RecursiveWork;
use Tey\Docket\Tests\Fixtures\Upload;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config(['queue.default' => 'database', 'queue.failed.driver' => null, 'queue.batching.database' => 'testing']);
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
    Schema::create('work_results', function (Blueprint $table) {
        $table->id();
        $table->string('label');
    });
});

it('caps depth at ten by default and records the rejected attempt without executing it', function () {
    expect(config('docket.max_depth'))->toBe(10);
    $descend = function (int $depth) use (&$descend): void {
        Docket::run('level.'.$depth, fn () => $descend($depth + 1));
    };
    expect(fn () => $descend(0))->toThrow(DepthExceeded::class);
    $leaf = Run::named('level.11')->sole();
    expect(Run::count())->toBe(12)
        ->and(Run::unfinished()->count())->toBe(0)
        ->and($leaf->error)->toBe(['code' => 'run.depth_exceeded', 'params' => ['max_depth' => 10]])
        ->and($leaf->parent->error)->toBe(['code' => 'run.child_failed', 'params' => ['child' => $leaf->id]])
        ->and(Docket::current())->toBeNull();
});

it('fails fast at a zero cap for inline and queued children even with allowRepeat', function (bool $queued, bool $caught) {
    config(['docket.max_depth' => 0]);
    Event::fake([RunFinished::class]);
    $execute = fn () => Docket::run('root', function () use ($queued, $caught) {
        try {
            if ($queued) {
                Docket::job(new RecordWork)->allowRepeat()->dispatch();
            } else {
                Docket::allowRepeat()->run('child', fn () => throw new LogicException('Must not execute'));
            }
        } catch (DepthExceeded $exception) {
            if (! $caught) {
                throw $exception;
            }
        }
    });
    if ($caught) {
        expect($execute()->status)->toBe(RunStatus::Partial);
    } else {
        expect($execute)->toThrow(DepthExceeded::class);
    }
    $root = Run::named('root')->sole();
    expect($root->children()->sole()->error['code'])->toBe('run.depth_exceeded')
        ->and($root->status)->toBe($caught ? RunStatus::Partial : RunStatus::Failed)
        ->and($root->children_failed)->toBe(1)
        ->and($root->pending_children)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    Event::assertDispatchedTimes(RunFinished::class, 2);
})->with([false, true])->with([false, true]);

it('allows deeper trees when the cap is null', function () {
    config(['docket.max_depth' => null]);
    $descend = function (int $depth) use (&$descend): void {
        Docket::run('level.'.$depth, fn () => $depth < 12 ? $descend($depth + 1) : null);
    };
    $descend(0);
    expect(Run::query()->succeeded()->count())->toBe(13);
});

it('starts linked work as an independent root with run and ticket targets', function (bool $ticket, string $source) {
    config(['docket.max_depth' => 0]);
    Queue::fake();
    $original = Docket::run('original', function (Run $original) use ($ticket, $source) {
        $target = $ticket ? $original->ticket() : $original;
        $linked = match ($source) {
            'inline' => Docket::linkedTo($target)->run('linked', fn () => 42),
            'job' => Docket::job(new RecordWork)->linkedTo($target)->dispatch(),
            'batch' => Docket::batch([new RecordWork])->linkedTo($target)->dispatch(),
            'chain' => Docket::chain([new RecordWork])->linkedTo($target)->dispatch(),
            default => throw new LogicException('Unknown test source.'),
        };
        expect($linked->run()->parent_run_id)->toBeNull()
            ->and($linked->run()->root_run_id)->toBeNull()
            ->and($linked->run()->linkedFrom->is($original))->toBeTrue()
            ->and($linked->run()->toArray()['linked_from_run_id'])->toBe($original->id)
            ->and(Docket::current())->toBe($original);
    })->run();
    expect($original->succeeded())->toBeTrue()
        ->and($original->linked()->count())->toBe(1)
        ->and($original->children_total)->toBe(0)
        ->and($original->progress->done)->toBe(0)
        ->and($original->progress->total)->toBeNull();
})->with([false, true])->with(['inline', 'job', 'batch', 'chain']);

it('treats linkedTo null as a no-op and gives a linked tree a fresh depth budget', function () {
    config(['docket.max_depth' => 1]);
    $root = Docket::linkedTo(null)->run('root', function () {
        Docket::linkedTo(null)->run('child', function (Run $child) {
            Docket::linkedTo($child)->linkedTo(null)->run('linked', function () {
                Docket::run('new child', fn () => null);
            });
        });
    })->run();
    $child = $root->children()->sole();
    $linked = $child->linked()->sole();
    expect($linked->parent_run_id)->toBeNull()
        ->and($linked->children()->sole()->root_run_id)->toBe($linked->id)
        ->and($root->descendants())->toHaveCount(1);
});

it('detects inline recursion by name and closure location and reports safe errors', function (bool $caught) {
    Event::fake([LoopDetectedEvent::class]);
    Exceptions::fake();
    $callback = function () use (&$callback, $caught) {
        try {
            Docket::run('recursive', $callback);
        } catch (LoopDetected $exception) {
            if (! $caught) {
                throw $exception;
            }
        }
    };
    if ($caught) {
        expect(Docket::run('recursive', $callback)->status)->toBe(RunStatus::Partial);
    } else {
        expect(fn () => Docket::run('recursive', $callback))->toThrow(LoopDetected::class);
    }
    $root = Run::whereNull('parent_run_id')->sole();
    $child = $root->children()->sole();
    expect($child->error)->toBe(['code' => 'run.loop_detected', 'params' => ['job' => Closure::class, 'repeats' => $root->id]])
        ->and($child->toArray())->not->toHaveKey('fingerprint')
        ->and(Docket::current())->toBeNull();
    Event::assertDispatched(LoopDetectedEvent::class, fn ($event) => $event->run->is($child)
        && $event->repeats->is($root) && strlen($event->fingerprint) === 64
        && $event->id === (unserialize(serialize($event)))->id);
    Exceptions::assertReported(LoopDetected::class);
    Exceptions::assertReportedCount(1);
})->with([true, false]);

it('allows sibling repeats and distinguishes inline names and closure locations', function () {
    $callback = fn () => null;
    $root = Docket::run('root', function () use ($callback) {
        Docket::run('same', $callback);
        Docket::run('same', $callback);
        Docket::run('same', function () {
            Docket::run('same', fn () => null);
        });
    })->run();
    expect($root->succeeded())->toBeTrue()->and($root->children_total)->toBe(3);
});

it('rescues queued recursion and continuation across worker boundaries', function (string $mode, bool $caught) {
    Event::fake([LoopDetectedEvent::class]);
    $ticket = Docket::job(new RecursiveWork($mode, $caught))->dispatch();
    artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();
    $run = $ticket->run();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($run->status)->toBe($mode === 'child' && $caught ? RunStatus::Partial : RunStatus::Failed)
        ->and($mode === 'child' ? $run->children()->sole()->error['code'] : $run->error['code'])->toBe('run.loop_detected');
    if ($mode === 'child' && ! $caught) {
        expect($run->error)->toBe(['code' => 'run.child_failed', 'params' => ['child' => $run->children()->sole()->id]]);
    }
    Event::assertDispatchedTimes(LoopDetectedEvent::class, 1);
})->with(['child', 'add'])->with([true, false]);

it('allows deliberate ancestor repeats on queued dispatch', function () {
    $ticket = Docket::job(new RecursiveWork('allow'))->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
    expect($ticket->run()->succeeded())->toBeTrue()
        ->and($ticket->run()->children()->sole()->succeeded())->toBeTrue();
});

it('atomically rejects repeated additions including duplicates in the same call', function (bool $sameCall) {
    Queue::fake();
    Event::fake([LoopDetectedEvent::class]);
    $run = Run::create(['name' => 'continuations', 'status' => RunStatus::Running]);
    if (! $sameCall) {
        $run->add(new RecordWork('page:1'));
    }
    expect(fn () => $run->add([new RecordWork('page:2'), new RecordWork('page:1'), new RecordWork('page:1')]))
        ->toThrow(LoopDetected::class);
    expect($run->refresh()->failed())->toBeTrue()
        ->and($run->error['code'])->toBe('run.loop_detected');
    Queue::assertPushed(RecordWork::class, $sameCall ? 0 : 1);
    Event::assertDispatchedTimes(LoopDetectedEvent::class, 1);
})->with([true, false]);

it('allows deliberate repeated additions but remembers them for subsequent checks', function () {
    Queue::fake();
    $run = Run::create(['name' => 'continuations']);
    $run->add(new RecordWork, allowRepeat: true)->add(new RecordWork, allowRepeat: true);
    expect(fn () => $run->add(new RecordWork))->toThrow(LoopDetected::class);
    Queue::assertPushed(RecordWork::class, 2);
});

it('uses docketKey in preference to other input while preserving succeeded-key skipping', function () {
    Queue::fake();
    $run = Run::create(['name' => 'keys']);
    $run->add(new KeyedWork('key', 'succeed'));
    expect(fn () => $run->add(new KeyedWork('key', 'fail')))->toThrow(LoopDetected::class);
    $other = Run::create(['name' => 'succeeded']);
    $other->itemSucceeded('key');
    $other->add(new KeyedWork('key'));
    expect($other->failed())->toBeFalse();
    Queue::assertPushed(KeyedWork::class, 1);
});

it('normalizes associative input and models but preserves list order and scalar types', function () {
    Queue::fake();
    $run = Run::create(['name' => 'inputs']);
    $first = (new Upload)->forceFill(['id' => 4, 'private' => 'original']);
    $second = (new Upload)->forceFill(['id' => 4, 'private' => 'changed']);
    $run->add(new RecursiveWork('input', input: ['model' => $first, 'map' => ['b' => 2, 'a' => 1], 'list' => [1, 2]]));
    $run->add(new RecursiveWork('input', input: ['model' => $first, 'map' => ['b' => 2, 'a' => 1], 'list' => [2, 1]]));
    $run->add(new RecursiveWork('input', input: ['model' => $first, 'map' => ['b' => 2, 'a' => '1'], 'list' => [1, 2]]));
    expect(fn () => $run->add(new RecursiveWork('input', input: ['list' => [1, 2], 'map' => ['a' => 1, 'b' => 2], 'model' => $second])))
        ->toThrow(LoopDetected::class);
    Queue::assertPushed(RecursiveWork::class, 3);
    expect(json_encode($run->refresh()))->not->toContain('original', 'changed', 'fingerprint');
});

it('does not mistake release or queue retry attempts for new work', function (string $behaviour) {
    Event::fake([LoopDetectedEvent::class]);
    $ticket = Docket::job(new RecordWork('attempt', $behaviour))->dispatch();
    artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 2])->assertSuccessful();
    expect($ticket->run()->succeeded())->toBeTrue()->and(DB::table('work_results')->count())->toBe(2);
    Event::assertNotDispatched(LoopDetectedEvent::class);
})->with(['release', 'retry']);

it('keeps fingerprint history local to a run including retry lineage', function () {
    Queue::fake();
    $first = Run::create(['name' => 'first']);
    $first->add(new RecordWork);
    $retry = Run::create(['name' => 'retry', 'retry_of_run_id' => $first->id]);
    $retry->add(new RecordWork);
    Queue::assertPushed(RecordWork::class, 2);
    expect($retry->failed())->toBeFalse();
});

it('rescues synchronous work and leaves no current run behind', function (string $mode) {
    $ticket = Docket::job(new RecursiveWork($mode))->dispatchSync();
    expect($ticket->status)->toBe(RunStatus::Failed)
        ->and($ticket->run()->error['code'])->toBe($mode === 'child' ? 'run.child_failed' : 'run.loop_detected')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Docket::current())->toBeNull();
})->with(['child', 'add']);

it('matches any ancestor rather than only the direct parent', function () {
    $callback = function () use (&$callback) {
        Docket::run('middle', fn () => Docket::run('root', $callback));
    };
    expect(fn () => Docket::run('root', $callback))->toThrow(LoopDetected::class);
    $root = Run::whereNull('parent_run_id')->sole();
    expect(Run::named('middle')->sole()->children()->sole()->error['params']['repeats'])->toBe($root->id)
        ->and(Run::unfinished()->count())->toBe(0);
});

it('persists and reports a loop independently of application commit or rollback', function (bool $commit) {
    Event::fake([LoopDetectedEvent::class]);
    Exceptions::fake();
    DB::beginTransaction();
    $ticket = Docket::run('transaction', function (Run $run) {
        $run->add(new RecordWork('page'));
        expect(fn () => $run->add(new RecordWork('page')))->toThrow(LoopDetected::class);
    });
    expect($ticket->status)->toBe(RunStatus::Failed);
    Event::assertDispatchedTimes(LoopDetectedEvent::class, 1);
    Exceptions::assertReported(LoopDetected::class);
    $commit ? DB::commit() : DB::rollBack();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($ticket->run()->error['code'])->toBe('run.loop_detected');
})->with([true, false]);

it('stops accepting work while a rescued run waits for its already queued child', function () {
    $ticket = Docket::run('parent', function (Run $run) {
        Docket::job(new RecordWork('child'))->dispatch();
        $run->add(new RecordWork('page'));
        expect(fn () => $run->add(new RecordWork('page')))->toThrow(LoopDetected::class);
        expect(fn () => $run->add(new RecordWork('extra')))->toThrow(LogicException::class);
    });
    expect($ticket->status)->toBe(RunStatus::Running);
    artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
    expect($ticket->run()->failed())->toBeTrue()
        ->and($ticket->run()->error['code'])->toBe('run.loop_detected')
        ->and(DB::table('work_results')->pluck('label')->all())->toBe(['child']);
});

it('starts identical linked work with fresh history and never rolls its failure into the original', function () {
    $callback = function (Run $run) use (&$callback) {
        if ($run->linked_from_run_id !== null) {
            $run->fail('linked.failed');
        }
        expect(fn () => Docket::linkedTo($run)->run('same', $callback))->toThrow(RunFailed::class);
    };
    $original = Docket::run('same', $callback)->run();
    expect($original->succeeded())->toBeTrue()
        ->and($original->linked()->sole()->error['code'])->toBe('linked.failed')
        ->and($original->children_total)->toBe(0);
});

it('fingerprints every chain member before allowing a continuation', function () {
    Queue::fake();
    $run = Docket::chain([new RecordWork('first'), new RecordWork('second')])->dispatch()->run();
    expect(fn () => $run->add(new RecordWork('second')))->toThrow(LoopDetected::class);
    expect($run->refresh()->error['params']['job'])->toBe(RecordWork::class);
});

it('distinguishes a model from an array shaped like its identity', function () {
    Queue::fake();
    $run = Run::create(['name' => 'input types']);
    $run->add(new RecursiveWork('input', input: (new Upload)->forceFill(['id' => 4])));
    $run->add(new RecursiveWork('input', input: ['model', Upload::class, '4']));
    Queue::assertPushed(RecursiveWork::class, 2);
});

it('checks all ancestor fingerprints in one query for multi-job children', function (int $depth) {
    Queue::fake();
    $descend = function (int $level) use (&$descend, $depth): void {
        Docket::run('level.'.$level, function (Run $run) use (&$descend, $depth, $level) {
            if ($level < $depth - 1) {
                $descend($level + 1);

                return;
            }
            $connection = $run->getConnection();
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            Docket::chain([new RecordWork('a'), new RecordWork('b'), new RecordWork('c')])->dispatch();
            $queries = collect($connection->getQueryLog())->pluck('query');
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            // One ancestor lookup plus the three jobs' own history lookups.
            expect($queries->filter(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, '"docket_fingerprints"'))->count())->toBe(4);
        });
    };
    $descend(0);
    unset($descend);
})->with([1, 5, 10]);
