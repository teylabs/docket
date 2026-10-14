<?php

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\AssertionFailedError;
use Tey\Docket\Events\RunDispatched;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Exceptions\ResultNotAvailable;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\PendingRun;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Testing\DocketFake;
use Tey\Docket\Testing\FakeRun;
use Tey\Docket\Tests\Fixtures\KeyedWork;
use Tey\Docket\Tests\Fixtures\PlainWork;
use Tey\Docket\Tests\Fixtures\Upload;
use Tey\Docket\Tests\Fixtures\User;
use Tey\Docket\Ticket;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Bus::fake();
    Event::fake([RunDispatched::class, RunFinished::class]);
    Docket::fake();
    // Any query, including a read, would break a fake used without migrations.
    $queries = new ArrayObject;
    app()->instance(ArrayObject::class, $queries);
    DB::listen(fn (QueryExecuted $event) => $queries->append($event->sql));
});

afterEach(function () {
    expect(app(ArrayObject::class))->toHaveCount(0);
});

it('swaps the container binding and records dispatches without running or queueing work', function () {
    expect(app(Tey\Docket\Docket::class))->toBeInstanceOf(DocketFake::class);
    Docket::assertNothingDispatched();
    $user = new User(['id' => 7]);
    $upload = new Upload(['id' => 9]);
    actingAs($user);
    $pending = Docket::job(new PlainWork)->name('rows.import')->about($upload);
    $ticket = $pending->dispatch();
    $pending->name('changed')->by(null);

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket->status)->toBe(RunStatus::Queued)
        ->and($ticket->name)->toBe('rows.import')
        ->and($ticket->run())->toBeInstanceOf(FakeRun::class);
    Docket::assertDispatched(PlainWork::class, fn (PendingRun $run) => $run->name === 'rows.import' && $run->subject === $upload && $run->initiator === $user && $run->jobs[0] instanceof PlainWork);
    Docket::dispatch(new PlainWork);
    Docket::dispatchSync(new PlainWork);
    Docket::job(new PlainWork)->dispatchSync();
    Docket::assertDispatched(PlainWork::class, 4);
    Docket::assertNotDispatched(KeyedWork::class);
    Docket::assertDispatched(KeyedWork::class, 0);
    Docket::assertNotDispatched(PlainWork::class, fn (PendingRun $run) => $run->name === 'missing');
    Docket::assertNotRan('rows.import');
    Bus::assertNothingDispatched();
    Event::assertNotDispatched(RunDispatched::class);
    Event::assertNotDispatched(RunFinished::class);
    expect(fn () => $ticket->result())->toThrow(ResultNotAvailable::class);
});

it('records closure dispatches batches and ordered chains separately', function () {
    $closure = fn () => throw new LogicException('A queued closure must not run.');
    Docket::job($closure)->dispatch();
    Docket::assertDispatched(Closure::class, fn (PendingRun $run) => $run->name === 'closure' && $run->jobs === [$closure]);
    Docket::batch(collect([new PlainWork, [new KeyedWork('first'), new KeyedWork('second')]]))->name('units.build')->dispatch();
    Docket::assertBatched(fn (PendingRun $run) => $run->name === 'units.build' && count($run->jobs) === 2 && is_array($run->jobs[1]));
    Docket::chain([new PlainWork, new KeyedWork('first')])->dispatch();
    Docket::assertChained([PlainWork::class, KeyedWork::class]);
    Docket::assertChained([new PlainWork, new KeyedWork('first')]);
    Docket::assertNotDispatched(PlainWork::class);
    Bus::assertNothingDispatched();
});

it('executes inline closures with their options and in-process results', function () {
    $subject = new Upload(['id' => 9]);
    $user = new User(['id' => 7]);
    $result = new stdClass;
    $ticket = Docket::about($subject)->by($user)->total(4)->run('profile.update', function (Run $run) use ($result) {
        expect($run)->toBeInstanceOf(FakeRun::class)->toBe(Docket::current());
        $run->step('save')->log('profile.saved')->result('profile:9')->checkpoint(9);

        return $result;
    });
    expect($ticket->result())->toBe($result)->and(Docket::current())->toBeNull();
    Docket::assertRan('profile.update', function (FakeRun $run) use ($subject, $user) {
        $run->assertStep('save');
        $run->assertTotal(4);
        $run->assertLogged('profile.saved');
        $run->assertResult('profile:9');
        $run->assertCheckpoint(9);

        return $run->name === 'profile.update' && $run->subject === $subject && $run->initiator === $user;
    });
    expect(Docket::run(fn () => null)->result())->toBeNull();
    Docket::assertRan('closure');
    Docket::name('named')->run(fn () => 1);
    Docket::assertRan('named');
    Docket::name('ignored')->run('explicit', fn () => 2);
    Docket::assertRan('explicit');
    Docket::assertNotRan('ignored');
    Docket::assertNothingDispatched();
    Event::assertNotDispatched(RunDispatched::class);
    Event::assertNotDispatched(RunFinished::class);
});

it('restores the parent current run on success and failure and records thrown inline runs', function () {
    $exception = new RuntimeException('private content');
    Docket::run('parent', function (Run $parent) use ($exception) {
        Docket::run('child', function (Run $child) use ($parent) {
            expect(Docket::current())->toBe($child);
            expect($child)->not->toBe($parent);
        });
        expect(Docket::current())->toBe($parent);
        try {
            Docket::run('failed.child', fn () => throw $exception);
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($exception);
        }
        expect(Docket::current())->toBe($parent);
        Docket::dispatch(new PlainWork);
    });
    Docket::assertRan('parent');
    Docket::assertRan('child');
    Docket::assertRan('failed.child');
    Docket::assertDispatched(PlainWork::class);
    expect(Docket::current())->toBeNull();
    expect(fn () => Docket::run('failed', fn (Run $run) => $run->fail('file.unsupported')))->toThrow(RunFailed::class);
    Docket::assertRan('failed', function (FakeRun $run) {
        $run->assertFailed('file.unsupported');

        return true;
    });
    expect(Docket::current())->toBeNull();
});

it('keeps ticket JSON responses and serialization free of reports and inline results', function () {
    Gate::define('cancel', fn ($user, Run $run) => $run->subject?->getKey() === 9);
    actingAs(new User(['id' => 1]));
    $ticket = Docket::job(new PlainWork)->about(new Upload(['id' => 9, 'secret' => 'subject-content']))->dispatch();
    $data = $ticket->toArray();
    expect(array_keys($data))->toBe(['schema_version', 'id', 'token', 'name', 'status', 'revision', 'poll_after_ms', 'links'])
        ->and($data['schema_version'])->toBe(1)
        ->and($data['status'])->toBe('queued')
        ->and($data['poll_after_ms'])->toBe(2000)
        ->and(array_keys($data['links']))->toBe(['poll', 'cancel']);
    $response = $ticket->toResponse(request());
    expect($response->getStatusCode())->toBe(202)
        ->and($response->headers->get('Location'))->toBe($data['links']['poll'])
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    app(AuthManager::class)->forgetGuards();
    expect($ticket->toArray()['links'])->not->toHaveKey('cancel');

    $inline = Docket::run(function (Run $run) {
        $run->checkpoint('private-checkpoint')->result('private-reference');

        return 'private-result';
    });
    expect(json_encode($inline))->not->toContain('private-')
        ->and(serialize($inline))->not->toContain('private-');
    $restored = unserialize(serialize($inline));
    expect($restored)->toBeInstanceOf(Ticket::class)
        ->and($restored->toArray())->toBe($inline->toArray());
    expect(fn () => $restored->result())->toThrow(ResultNotAvailable::class);
});

it('starts a fresh recorder for every fake without retaining previous assertions', function () {
    $old = Docket::fake();
    Docket::dispatch(new PlainWork);
    Docket::run('old', fn () => null);
    $fresh = Docket::fake();
    expect($fresh)->not->toBe($old);
    Docket::assertNothingDispatched();
    Docket::assertNotRan('old');
    $old->assertDispatched(PlainWork::class);
    $old->assertRan('old');
});

it('keeps option starters isolated and does not simulate lifecycle callbacks or deduplication', function () {
    $callback = fn () => throw new LogicException('Lifecycle callbacks are not executed by the fake.');
    $pending = Docket::unique()->idempotencyKey('same')->staleAfter(30)->expiresAt(now()->addDay())
        ->then($callback)->catch($callback)->finally($callback);
    $first = $pending->run('repeat', fn () => 1);
    $second = $pending->run('repeat', fn () => 2);
    expect($first->id)->not->toBe($second->id);
    expect($first->result())->toBe(1)->and($second->result())->toBe(2)
        ->and($first->status)->toBe(RunStatus::Running);
    foreach ([
        Docket::by(null), Docket::total(2), Docket::staleAfter(30), Docket::expiresAt(now()->addDay()),
        Docket::then($callback), Docket::catch($callback), Docket::finally($callback),
        Docket::idempotencyKey('key'), Docket::linkedTo(null), Docket::allowRepeat(),
    ] as $options) {
        expect($options->run(fn () => 'done')->result())->toBe('done');
    }
    Docket::about(new Upload(['id' => 9]));
    Docket::job(new PlainWork)->dispatch();
    Docket::assertDispatched(PlainWork::class, fn (PendingRun $run) => $run->subject === null && $run->initiator === null);
    Bus::assertNothingDispatched();
});

it('fails dispatch assertions for missing or mismatched work', function (Closure $assertion) {
    Docket::job(new PlainWork)->name('actual')->dispatch();
    Docket::batch([new PlainWork])->dispatch();
    Docket::chain([new PlainWork, new KeyedWork('actual')])->dispatch();
    Docket::run('actual', fn () => null);
    expect($assertion)->toThrow(AssertionFailedError::class);
})->with([
    'missing job' => fn () => Docket::assertDispatched(KeyedWork::class),
    'wrong count' => fn () => Docket::assertDispatched(PlainWork::class, 2),
    'wrong callback' => fn () => Docket::assertDispatched(PlainWork::class, fn (PendingRun $run) => $run->name === 'wrong'),
    'unexpected job' => fn () => Docket::assertNotDispatched(PlainWork::class),
    'unexpected matching job' => fn () => Docket::assertNotDispatched(PlainWork::class, fn (PendingRun $run) => $run->name === 'actual'),
    'wrong batch' => fn () => Docket::assertBatched(fn (PendingRun $run) => $run->name === 'wrong'),
    'wrong chain order' => fn () => Docket::assertChained([KeyedWork::class, PlainWork::class]),
    'wrong chain input' => fn () => Docket::assertChained([new PlainWork, new KeyedWork('wrong')]),
    'wrong chain length' => fn () => Docket::assertChained([PlainWork::class]),
    'unexpected dispatch' => fn () => Docket::assertNothingDispatched(),
    'missing inline run' => fn () => Docket::assertRan('missing'),
    'wrong inline callback' => fn () => Docket::assertRan('actual', fn (FakeRun $run) => $run->subject !== null),
    'unexpected inline run' => fn () => Docket::assertNotRan('actual'),
]);

it('retains builder validation under the fake', function (Closure $operation) {
    expect($operation)->toThrow(InvalidArgumentException::class);
    Docket::assertNothingDispatched();
    Docket::assertNotRan('invalid');
})->with([
    'missing closure' => fn () => Docket::run('invalid'),
    'inline on job' => fn () => Docket::job(new PlainWork)->run('invalid', fn () => null),
    'empty batch' => fn () => Docket::batch([])->dispatch(),
    'empty chain' => fn () => Docket::chain([])->dispatch(),
    'empty batch chain' => fn () => Docket::batch([[]])->dispatch(),
    'missing job' => fn () => Docket::name('invalid')->dispatch(),
    'negative total' => fn () => Docket::total(-1),
    'invalid stale interval' => fn () => Docket::staleAfter(0),
]);
