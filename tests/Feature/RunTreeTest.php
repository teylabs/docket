<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function () {
    config(['queue.default' => 'database', 'queue.failed.driver' => null]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('persists inline trees with independent tickets and breadth first descendants', function () {
    $root = Docket::run('root', function (Run $root) {
        $first = Docket::run('first', function (Run $child) use ($root) {
            expect($child->parent->is($root))->toBeTrue()
                ->and($child->root->is($root))->toBeTrue();
            Docket::run('grandchild', fn () => 'grandchild result');
        });
        $second = Docket::run('second', fn () => 'second result');
        expect($first->id)->not->toBe($second->id)
            ->and($first->token)->not->toBe($second->token)
            ->and($second->result())->toBe('second result')
            ->and(Docket::current())->toBe($root);
    })->run();

    expect($root->parent)->toBeNull()->and($root->root->is($root))->toBeTrue()
        ->and($root->root_run_id)->toBeNull()
        ->and($root->children()->pluck('name')->all())->toBe(['first', 'second'])
        ->and($root->descendants()->pluck('name')->all())->toBe(['first', 'second', 'grandchild'])
        ->and($root->descendants()->pluck('root_run_id')->unique()->all())->toBe([$root->id])
        ->and($root->toArray()['children'])->toBe(['total' => 2, 'running' => 0, 'succeeded' => 2, 'partial' => 0, 'failed' => 0])
        ->and($root->toArray()['links']['children'])->toEndWith('/'.$root->token.'/children')
        ->and($root->progress->toArray())->toBe(['done' => 2, 'total' => 2, 'percent' => 100]);

    config(['docket.max_depth' => 1]);
    expect($root->descendants()->pluck('name')->all())->toBe(['first', 'second']);
    config(['docket.max_depth' => null]);
    expect($root->descendants())->toHaveCount(3);
});

it('rolls up weighted attempts and corrected totals without mixing item counts or double counting', function () {
    $root = Docket::total(2)->run('root', function (Run $root) {
        $root->itemSucceeded('own');
        Docket::run('child', function (Run $child) use ($root) {
            expect($root->refresh()->progress->toArray())->toBe(['done' => 1, 'total' => 3, 'percent' => 33]);
            Docket::run('leaf', function (Run $leaf) use ($root, $child) {
                $leaf->total(10)->itemSucceeded('weighted', count: 5);
                $stale = Run::findOrFail($leaf->id);
                $leaf->itemFailed('invalid', code: 'row.invalid');
                $stale->itemSucceeded('weighted', count: 5);
                expect($root->refresh()->progress->done)->toBe(7)
                    ->and($root->progress->total)->toBe(12)
                    ->and($child->refresh()->progress->done)->toBe(6);
                $leaf->total(6);
                expect($root->refresh()->progress->total)->toBe(8);
            });
            $child->track(['own:1', 'own:2']);
            $child->itemSkipped('own:1', code: 'row.duplicate');
            $child->total(1);
        });
        expect($root->refresh()->status)->toBe(RunStatus::Running);
    })->run();

    expect($root->status)->toBe(RunStatus::Partial)
        ->and($root->progress->toArray())->toBe(['done' => 8, 'total' => 9, 'percent' => 88])
        ->and($root->toArray()['counts'])->toBe(['processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0])
        ->and($root->children_partial)->toBe(1)
        ->and($root->children_failed)->toBe(0);
});

it('counts an unknown total child as one unit and an explicit zero as zero', function () {
    $root = Docket::run('root', function (Run $root) {
        Docket::run('unknown', function (Run $child) use ($root) {
            $child->itemSucceeded('unbounded', count: 50);
            expect($child->progress->total)->toBeNull()
                ->and($root->refresh()->progress->toArray())->toBe(['done' => 0, 'total' => 1, 'percent' => 0]);
        });
        Docket::total(0)->run('empty', fn () => null);
    })->run();
    expect($root->progress->toArray())->toBe(['done' => 1, 'total' => 1, 'percent' => 100]);
});

it('distinguishes caught child failures from unchanged exceptions escaping each parent', function (bool $caught, bool $explicit) {
    $exception = $explicit ? new RunFailed('file.unsupported', ['id' => 5]) : new RuntimeException('private content');
    $execute = function () use ($exception, $caught) {
        return Docket::run('root', function () use ($exception, $caught) {
            try {
                Docket::run('child', fn () => Docket::run('leaf', fn () => throw $exception));
            } catch (RuntimeException $error) {
                if (! $caught) {
                    throw $error;
                }
            }
        });
    };
    if ($caught) {
        expect($execute()->status)->toBe(RunStatus::Partial);
    } else {
        try {
            $execute();
            Assert::fail('Expected the child exception.');
        } catch (RuntimeException $error) {
            expect($error)->toBe($exception);
        }
    }
    $root = Run::named('root')->sole();
    $child = Run::named('child')->sole();
    $leaf = Run::named('leaf')->sole();
    expect($child->error)->toBe(['code' => 'run.child_failed', 'params' => ['child' => $leaf->id]])
        ->and($root->error)->toBe($caught ? null : ['code' => 'run.child_failed', 'params' => ['child' => $child->id]])
        ->and($root->children_failed)->toBe(1)
        ->and($root->progress->done)->toBe(1)
        ->and(json_encode($root))->not->toContain('private content')
        ->and(Docket::current())->toBeNull();
})->with([true, false])->with([true, false]);

it('does not attribute a different parent exception to a previously caught child', function () {
    expect(fn () => Docket::run('root', function () {
        try {
            Docket::run('child', fn () => throw new RuntimeException);
        } catch (RuntimeException) {
            throw new LogicException;
        }
    }))->toThrow(LogicException::class);
    expect(Run::named('root')->sole()->error['code'])->toBe('run.exception');
});

it('waits for queued descendants before callbacks and finishes each run once', function () {
    Event::fake([RunFinished::class]);
    $ticket = Docket::then(fn (Run $run) => $run->logs()->create(['code' => 'callback.then']))
        ->finally(fn (Run $run) => $run->logs()->create(['code' => 'callback.finally']))
        ->run('root', function () {
            Docket::job(function (Run $child) {
                Docket::run('inline', fn (Run $run) => $run->total(4)->itemSucceeded('part', count: 4));
                Docket::job(fn (Run $run) => $run->total(2)->itemSucceeded('part', count: 2))->name('grandchild')->dispatch();
            })->name('child')->dispatch();

            return 42;
        });
    expect($ticket->status)->toBe(RunStatus::Running)->and($ticket->result())->toBe(42)
        ->and($ticket->run()->children_running)->toBe(1)
        ->and($ticket->run()->finished_at)->toBeNull()
        ->and($ticket->run()->logs()->count())->toBe(0);
    Event::assertNotDispatched(RunFinished::class);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->status)->toBe(RunStatus::Running)
        ->and(Run::named('child')->sole()->status)->toBe(RunStatus::Running)
        ->and($ticket->run()->progress->toArray())->toBe(['done' => 4, 'total' => 5, 'percent' => 80]);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($ticket->run()->succeeded())->toBeTrue()
        ->and($ticket->run()->progress->toArray())->toBe(['done' => 6, 'total' => 6, 'percent' => 100])
        ->and($ticket->run()->logs()->pluck('code')->all())->toBe(['callback.then', 'callback.finally']);
    Event::assertDispatchedTimes(RunFinished::class, 4);
    expect(Docket::current())->toBeNull();
});

it('handles queued failure as partial and excludes detached children from progress and completion', function (bool $detached) {
    $root = Docket::run('root', function () use ($detached) {
        $pending = Docket::job(fn () => throw new RuntimeException('private content'))->name('child')->total(100);
        if ($detached) {
            $pending->detached();
        }
        $pending->dispatch();
    })->run();
    expect($root->status)->toBe($detached ? RunStatus::Succeeded : RunStatus::Running)
        ->and($root->children_running)->toBe(1)
        ->and($root->children()->sole()->parent_run_id)->toBe($root->id)
        ->and($root->progress->total)->toBe($detached ? null : 100);
    artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
    expect($root->refresh()->status)->toBe($detached ? RunStatus::Succeeded : RunStatus::Partial)
        ->and($root->children_failed)->toBe(1)
        ->and($root->children_running)->toBe(0)
        ->and($root->error)->toBeNull();
})->with([true, false]);

it('keeps a parent open after an uncaught failure until its other children finish', function () {
    Event::fake([RunFinished::class]);
    expect(fn () => Docket::run('root', function () {
        Docket::job(fn () => null)->name('queued')->dispatch();
        Docket::run('failed', fn () => throw new RuntimeException);
    }))->toThrow(RuntimeException::class);
    $root = Run::named('root')->sole();
    expect($root->status)->toBe(RunStatus::Running)->and($root->error['code'])->toBe('run.child_failed')
        ->and($root->toArray()['error'])->toBeNull();
    Event::assertNotDispatched(RunFinished::class, fn ($event) => $event->run->id === $root->id);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($root->refresh()->failed())->toBeTrue()->and($root->error['code'])->toBe('run.child_failed');
    Event::assertDispatchedTimes(RunFinished::class, 3);
});

it('waits for both added work and children whichever finishes first', function (bool $childFirst) {
    $root = Docket::run('root', function (Run $run) use ($childFirst) {
        $child = function () {
            Docket::job(function (Run $child) {
                $child->total(2)->itemSucceeded('part', count: 2);
            })->dispatch();
        };
        if ($childFirst) {
            $child();
        }
        $run->add(function (Run $run) {
            $run->total(3)->itemSucceeded('own', count: 3);
        });
        if (! $childFirst) {
            $child();
        }
    })->run();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($root->refresh()->status)->toBe(RunStatus::Running);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($root->refresh()->succeeded())->toBeTrue()
        ->and($root->children_total)->toBe(1)
        ->and($root->progress->toArray())->toBe(['done' => 5, 'total' => 5, 'percent' => 100]);
})->with([true, false]);

it('waits for application commits and settles rolled back trees', function (bool $rollback) {
    DB::beginTransaction();
    $ticket = Docket::run('root', function () {
        Docket::run('inline', fn () => null);
        Docket::job(fn () => null)->name('queued')->dispatch();
    });
    expect($ticket->status)->toBe(RunStatus::Running)->and(DB::table('jobs')->count())->toBe(0);
    $rollback ? DB::rollBack() : DB::commit();
    if (! $rollback) {
        expect($ticket->run()->status)->toBe(RunStatus::Running);
        artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    }
    expect($ticket->run()->status)->toBe($rollback ? RunStatus::Failed : RunStatus::Succeeded)
        ->and($ticket->run()->children_running)->toBe(0)
        ->and($ticket->run()->progress->done)->toBe(2);
})->with([true, false]);

it('counts cancelled children only in total and does not turn their parent partial', function () {
    $root = Docket::run('root', function () {
        Docket::run('cancelled', function (Run $child) {
            $child->update(['cancel_requested' => true]);
            $child->throwIfCancelled();
        });
    })->run();
    expect($root->succeeded())->toBeTrue()
        ->and($root->toArray()['children'])->toBe(['total' => 1, 'running' => 0, 'succeeded' => 0, 'partial' => 0, 'failed' => 0])
        ->and($root->progress->done)->toBe(1);
});

it('filters tree nodes and inaccessible branches with the explicit or signed in viewer', function () {
    Gate::policy(Run::class, stdClass::class);
    $root = Docket::run('root', function () {
        Docket::run('visible', function (Run $run) {
            $run->log('older')->log('latest', ['id' => 3])->step('validate')->total(2)->itemSucceeded('part', result: 'private-reference');
            $run->checkpoint('private-checkpoint');
        });
        Docket::run('hidden', fn () => Docket::run('hidden descendant', fn () => null));
    })->run();
    $viewer = new GenericUser(['id' => 1]);
    Gate::define('view', fn (GenericUser $user, Run $run) => $user->getAuthIdentifier() === 1 && $run->name !== 'hidden');
    $tree = $root->tree($viewer);
    expect(array_keys($tree))->toBe(['id', 'token', 'name', 'status', 'step', 'progress', 'counts', 'latest_log', 'children'])
        ->and($tree['latest_log'])->toBeNull()
        ->and($tree['children'])->toHaveCount(1)
        ->and($tree['children'][0]['latest_log']['code'])->toBe('latest')
        ->and($tree['children'][0]['latest_log']['params'])->toEqual((object) ['id' => 3])
        ->and($tree['children'][0]['progress'])->toBe(['done' => 1, 'total' => 2, 'percent' => 50])
        ->and(json_encode($tree))->not->toContain('private-reference', 'private-checkpoint', 'hidden')
        ->and($root->children_total)->toBe(2)
        ->and($root->tree(new GenericUser(['id' => 2])))->toBe([])
        ->and($root->tree())->toBe([]);
    actingAs($viewer);
    expect($root->tree())->toEqual($tree);
    $child = Run::named('visible')->sole();
    expect(json_encode($child->ticket()))->not->toContain('latest', 'private-reference');
});

it('keeps synchronous dispatch nested and plain dispatch untracked', function (bool $fails) {
    $root = Docket::run('root', function (Run $root) use ($fails) {
        try {
            Docket::job(function (Run $child) use ($fails) {
                Docket::run('inline', fn () => null);
                if ($fails) {
                    throw new RuntimeException;
                }
            })->name('sync child')->dispatchSync();
        } catch (RuntimeException) {
            expect(Docket::current())->toBe($root);
        }
        dispatch(function () {
            if (Docket::current() !== null) {
                throw new LogicException('Untracked work inherited a run.');
            }
        });
    })->run();
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($root->status)->toBe($fails ? RunStatus::Partial : RunStatus::Succeeded)
        ->and($root->children_total)->toBe(1)
        ->and(Run::named('inline')->sole()->parent->name)->toBe('sync child')
        ->and(Run::count())->toBe(3);
})->with([true, false]);

it('attributes an uncaught synchronous dispatch exception to its child', function () {
    expect(fn () => Docket::run('root', function () {
        Docket::job(fn () => throw new RuntimeException)->name('child')->dispatchSync();
    }))->toThrow(RuntimeException::class);
    expect(Run::named('root')->sole()->error)->toBe([
        'code' => 'run.child_failed', 'params' => ['child' => Run::named('child')->sole()->id],
    ]);
});

it('does not attach deduplicated runs or count the same queued completion twice', function () {
    Event::fake([RunFinished::class]);
    $existing = Docket::idempotencyKey('shared')->run('existing', fn () => null);
    $root = Docket::run('root', function () use ($existing) {
        expect(Docket::idempotencyKey('shared')->run('existing', fn () => throw new LogicException)->id)->toBe($existing->id);
        Docket::job(fn (Run $run) => $run->total(1)->itemSucceeded('part'))->dispatch();
    })->run();
    $payload = DB::table('jobs')->sole()->payload;
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    Queue::pushRaw($payload);
    artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($root->refresh()->children_total)->toBe(1)
        ->and($root->children_succeeded)->toBe(1)
        ->and($root->children_running)->toBe(0)
        ->and($root->progress->done)->toBe(1)
        ->and($existing->run()->parent_run_id)->toBeNull();
    Event::assertDispatchedTimes(RunFinished::class, 3);
});

it('updates one counter row per ancestor regardless of sibling count', function () {
    Docket::run('root', function (Run $root) {
        foreach (range(1, 20) as $index) {
            Docket::run('sibling.'.$index, fn () => null);
        }
        Docket::run('child', function (Run $child) use ($root) {
            Docket::total(10)->run('leaf', function (Run $leaf) use ($root, $child) {
                $connection = $leaf->getConnection();
                $connection->enableQueryLog();
                $connection->flushQueryLog();
                $leaf->itemSucceeded('part', count: 3);
                $queries = collect($connection->getQueryLog())->pluck('query');
                $connection->disableQueryLog();
                expect($queries->filter(fn (string $sql) => str_starts_with($sql, 'update "docket_runs"'))->count())->toBe(3)
                    ->and($queries->filter(fn (string $sql) => str_contains($sql, '"children_done" = "children_done" + 3'))->count())->toBe(2)
                    ->and($root->refresh()->progress->done)->toBe(23)
                    ->and($child->refresh()->progress->done)->toBe(3);
            });
        });
    });
});
