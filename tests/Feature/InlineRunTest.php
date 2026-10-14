<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Tey\Docket\Events\RunDispatched;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Exceptions\ResultNotAvailable;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\Upload;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;

it('runs immediately with a durable running record and returns a final JSON ticket', function () {
    Event::fake([RunDispatched::class, RunFinished::class]);
    expect(Docket::current())->toBeNull();
    $value = new stdClass;
    $value->content = 'private-result';
    $ticket = Docket::run('report.build', function (Run $run) use ($value) {
        expect($run->status)->toBe(RunStatus::Running)
            ->and($run->fresh()->status)->toBe(RunStatus::Running)
            ->and(Docket::current())->toBe($run);

        return $value;
    });

    expect($ticket->name)->toBe('report.build')
        ->and($ticket->status)->toBe(RunStatus::Succeeded)
        ->and($ticket->result())->toBe($value)
        ->and($ticket->run()->result)->toBeNull()
        ->and($ticket->run()->finished_at)->not->toBeNull()
        ->and($ticket->run()->expires_at)->toEqual($ticket->run()->finished_at->addDay())
        ->and($ticket->toResponse(Request::create('/'))->getStatusCode())->toBe(200)
        ->and($ticket->toArray())->toHaveKeys(['schema_version', 'id', 'token', 'name', 'status', 'revision', 'poll_after_ms', 'links'])
        ->and($ticket->toArray())->toHaveCount(8)
        ->and(json_encode($ticket))->not->toContain('private-result')
        ->and(serialize($ticket))->not->toContain('private-result')
        ->and(fn () => unserialize(serialize($ticket))->result())->toThrow(ResultNotAvailable::class)
        ->and(fn () => $ticket->run()->ticket()->result())->toThrow(ResultNotAvailable::class)
        ->and(Docket::current())->toBeNull();
    Event::assertNotDispatched(RunDispatched::class);
    Event::assertDispatchedTimes(RunFinished::class, 1);
});

it('keeps null false scalar array and unserializable closure results only on their tickets', function (mixed $value) {
    $ticket = Docket::run(fn () => $value);
    expect($ticket->name)->toBe('closure')->and($ticket->result())->toBe($value)
        ->and(unserialize(serialize($ticket))->toArray())->toBe($ticket->toArray());
})->with([null, false, 42, [['id' => 3]], [fn () => 'private']]);

it('uses independent option starters and keeps inline options and authenticated defaults', function () {
    foreach (['uploads', 'users'] as $table) {
        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
    $subject = Upload::create();
    $user = User::create();
    actingAs($user);
    $expiry = now()->addHours(2)->startOfSecond();
    $ticket = Docket::about($subject)->total(15)->staleAfter(60)->expiresAt($expiry)->name('report.build')->run(fn () => 5);
    $run = $ticket->run();
    expect($run->subject->is($subject))->toBeTrue()
        ->and($run->initiator->is($user))->toBeTrue()
        ->and($run->name)->toBe('report.build')
        ->and($run->total)->toBe(15)
        ->and($run->getAttribute('stale_after'))->toBe(60)
        ->and($run->expires_at)->toEqual($expiry);
    $other = Docket::by(null)->run(fn () => null)->run();
    expect($other->subject)->toBeNull()->and($other->initiator)->toBeNull()->and($other->total)->toBeNull();
    expect(Docket::name('ignored')->run('explicit', fn () => null)->name)->toBe('explicit');
});

it('rejects pending jobs batches chains and missing callbacks before starting', function () {
    foreach ([Docket::job(new stdClass), Docket::batch([new stdClass]), Docket::chain([new stdClass])] as $pending) {
        expect(fn () => $pending->run(fn () => null))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => Docket::run('missing'))->toThrow(InvalidArgumentException::class)
        ->and(Run::count())->toBe(0);
});

it('unwinds nested calls on success exceptions and explicit failure', function (string $exit) {
    Docket::run('outer', function (Run $outer) use ($exit) {
        try {
            Docket::run('inner', function (Run $inner) use ($exit) {
                expect(Docket::current())->toBe($inner);
                if ($exit === 'throw') {
                    throw new RuntimeException('private-message');
                }
                if ($exit === 'fail') {
                    $inner->fail('file.unsupported', ['id' => 5]);
                }
            });
        } catch (RuntimeException) {
            expect(Docket::current())->toBe($outer);
        }
        expect(Docket::current())->toBe($outer);
    });
    expect(Docket::current())->toBeNull();
})->with(['return', 'throw', 'fail']);

it('records only the exception class and rethrows the identical throwable', function (Throwable $exception) {
    try {
        Docket::run('report.build', fn () => throw $exception);
        Assert::fail('Expected the original exception.');
    } catch (Throwable $caught) {
        expect($caught)->toBe($exception);
    }
    $run = Run::sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe(['code' => 'run.exception', 'params' => []])
        ->and($run->logs()->sole()->params)->toBe(['exception' => $exception::class])
        ->and(json_encode($run->getAttributes()))->not->toContain('private-message')
        ->and(json_encode($run->logs()->get()))->not->toContain('private-message')
        ->and(Docket::current())->toBeNull();
})->with([new RuntimeException('private-message'), new Error('private-message')]);

it('records explicit failure codes and scalar params and rethrows RunFailed', function () {
    expect(fn () => Docket::run(fn (Run $run) => $run->fail('file.unsupported', ['id' => 5])))
        ->toThrow(RunFailed::class, 'file.unsupported');
    expect(Run::sole()->error)->toBe(['code' => 'file.unsupported', 'params' => ['id' => 5]])
        ->and(Run::sole()->failed())->toBeTrue()->and(Docket::current())->toBeNull();
});

it('rejects the reserved run prefix for application failures', function () {
    expect(fn () => Docket::run(fn (Run $run) => $run->fail('run.failed')))->toThrow(InvalidArgumentException::class);
    expect(Run::sole()->error['code'])->toBe('run.exception');
});

it('waits for the outer commit and preserves its running ticket and immediate result', function () {
    Event::fake([RunFinished::class]);
    DB::beginTransaction();
    DB::beginTransaction();
    $ticket = Docket::run(fn () => 42);
    DB::commit();
    expect($ticket->status)->toBe(RunStatus::Running)->and($ticket->result())->toBe(42)
        ->and($ticket->run()->finished_at)->toBeNull()
        ->and($ticket->toResponse(Request::create('/'))->getStatusCode())->toBe(202)
        ->and(Docket::current())->toBeNull();
    Event::assertNotDispatched(RunFinished::class);
    DB::commit();
    expect($ticket->status)->toBe(RunStatus::Running)->and($ticket->run()->succeeded())->toBeTrue();
    Event::assertDispatchedTimes(RunFinished::class, 1);
});

it('records rollback at either the savepoint or outer transaction without losing the run', function (bool $commitInner) {
    DB::beginTransaction();
    DB::beginTransaction();
    $ticket = Docket::run(fn () => 'immediate');
    if ($commitInner) {
        DB::commit();
        DB::rollBack();
    } else {
        DB::rollBack();
        DB::commit();
    }
    expect($ticket->run()->failed())->toBeTrue()
        ->and($ticket->run()->error)->toBe(['code' => 'run.rolled_back', 'params' => []])
        ->and($ticket->result())->toBe('immediate');
})->with([true, false]);

it('preserves an exception failure when the application transaction subsequently rolls back', function () {
    expect(fn () => DB::transaction(fn () => Docket::run(fn () => throw new RuntimeException('private'))))
        ->toThrow(RuntimeException::class);
    expect(Run::sole()->error['code'])->toBe('run.exception')->and(Docket::current())->toBeNull();
});

it('does not rerun unique or idempotent work and does not invent an in-process result', function () {
    $first = Docket::idempotencyKey('key')->run('report.build', fn () => 1);
    $second = Docket::idempotencyKey('key')->run('report.build', fn () => throw new RuntimeException);
    expect($second->id)->toBe($first->id)->and(fn () => $second->result())->toThrow(ResultNotAvailable::class);
    DB::beginTransaction();
    $first = Docket::unique()->run('cache.warm', fn () => 2);
    $second = Docket::unique()->run('cache.warm', fn () => throw new RuntimeException);
    expect($second->id)->toBe($first->id)->and(fn () => $second->result())->toThrow(ResultNotAvailable::class);
    DB::commit();
    expect(Docket::unique()->run('cache.warm', fn () => 3)->result())->toBe(3);
});

it('runs completion callbacks after commit with no inline run current', function (bool $rollback) {
    DB::beginTransaction();
    $ticket = Docket::then(function (Run $run) {
        $run->logs()->create(['code' => 'callback.then']);
    })->catch(function (Run $run) {
        $run->logs()->create(['code' => 'callback.catch']);
    })->finally(function (Run $run) {
        $run->logs()->create(['code' => Docket::current() === null ? 'callback.clear' : 'callback.leaked']);
    })->run(fn () => null);
    expect($ticket->run()->logs()->count())->toBe(0);
    $rollback ? DB::rollBack() : DB::commit();
    expect($ticket->run()->logs()->pluck('code')->all())->toBe([$rollback ? 'callback.catch' : 'callback.then', 'callback.clear']);
})->with([true, false]);

it('observes transactions ended by the closure itself without finishing before the work returns', function (bool $rollback) {
    DB::beginTransaction();
    $ticket = Docket::run(function (Run $run) use ($rollback) {
        $rollback ? DB::rollBack() : DB::commit();
        expect($run->fresh()->status)->toBe($rollback ? RunStatus::Failed : RunStatus::Running);
    });
    expect($ticket->status)->toBe($rollback ? RunStatus::Failed : RunStatus::Succeeded);
})->with([true, false]);

it('survives application writes rolled back on a separate configured docket database', function () {
    config(['database.connections.records' => ['driver' => 'sqlite', 'database' => ':memory:'], 'docket.connection' => 'records']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_13_000000_create_docket_tables.php';
    $migration->up();
    Schema::create('work', fn (Blueprint $table) => $table->string('value'));
    DB::beginTransaction();
    $ticket = Docket::run(fn () => DB::table('work')->insert(['value' => 'private']));
    DB::rollBack();
    expect(DB::table('work')->count())->toBe(0)
        ->and($ticket->run()->error['code'])->toBe('run.rolled_back');
});

it('releases in-process results with their tickets even while waiting for commit', function () {
    DB::beginTransaction();
    $ticket = Docket::run(fn () => new stdClass);
    $reference = WeakReference::create($ticket->result());
    expect($reference->get())->not->toBeNull();
    unset($ticket);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
    DB::commit();
});
