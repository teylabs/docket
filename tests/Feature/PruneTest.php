<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tey\Docket\Events\RunExpiring;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

use function Pest\Laravel\artisan;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

it('reaps queued and running runs only after their last report deadline and invokes completion once', function (RunStatus $status) {
    freezeTime();
    $run = Run::create(['name' => 'stale', 'status' => $status, 'stale_after' => 10]);
    Event::fake([RunFinished::class]);
    travel(10)->seconds();
    artisan('docket:prune')->assertSuccessful();
    expect($run->refresh()->finished())->toBeFalse();
    $run->step('alive');
    travel(10)->seconds();
    artisan('docket:prune')->assertSuccessful();
    expect($run->refresh()->finished())->toBeFalse();
    $run->cancel(); // Control changes do not extend the report deadline.
    travel(1)->seconds();
    artisan('docket:prune')->assertSuccessful();
    artisan('docket:prune')->assertSuccessful();
    expect($run->refresh()->failed())->toBeTrue()
        ->and($run->error)->toBe(['code' => 'run.stalled', 'params' => []])
        ->and($run->expires_at)->toEqual(now()->startOfSecond()->addDay());
    Event::assertDispatchedTimes(RunFinished::class, 1);
})->with([RunStatus::Queued, RunStatus::Running]);

it('fails a lost child and completes its waiting parent through normal roll up', function () {
    freezeTime();
    config(['queue.default' => 'database']);
    Queue::fake();
    $root = Docket::staleAfter(1)->run('parent', static function () {
        Docket::job(static fn () => null)->staleAfter(10)->dispatch();
    })->run();
    travel(2)->seconds();
    artisan('docket:prune')->assertSuccessful();
    expect($root->refresh()->finished())->toBeFalse();
    travel(9)->seconds();
    artisan('docket:prune')->assertSuccessful();
    expect($root->refresh()->partial())->toBeTrue()
        ->and($root->children()->sole()->error['code'])->toBe('run.stalled');
});

it('runs catch and finally for stale work and uses the configured default deadline', function () {
    freezeTime();
    config(['queue.default' => 'database', 'docket.stale_after' => 5]);
    Queue::fake();
    $run = Docket::job(static fn () => null)
        ->catch(static fn (Run $run) => $run->logs()->create(['code' => 'callback.catch']))
        ->finally(static fn (Run $run) => $run->logs()->create(['code' => 'callback.finally']))->dispatch()->run();
    travel(6)->seconds();
    artisan('docket:prune')->assertSuccessful();
    artisan('docket:prune')->assertSuccessful();
    expect($run->refresh()->failed())->toBeTrue()
        ->and($run->logs()->pluck('code')->all())->toBe(['callback.catch', 'callback.finally']);
});

it('expires finished records by TTL or explicit expiry but never deletes unfinished work', function (bool $explicit) {
    freezeTime();
    config(['docket.ttl' => 10]);
    $pending = Docket::name('retained');
    if ($explicit) {
        $pending->expiresAt(now()->addSeconds(5));
    }
    $run = $pending->run(static function (Run $run) {
        $run->itemSucceeded('one', result: 'file:1')->log('file.created');
    })->run();
    $unfinished = Run::create(['name' => 'unfinished', 'expires_at' => now()]);
    $ids = [];
    Event::listen(RunExpiring::class, function (RunExpiring $event) use (&$ids) {
        expect(Run::find($event->run->id))->not->toBeNull()
            ->and($event->run->items()->count())->toBe(1)->and($event->run->logs()->count())->toBe(1);
        $ids[] = $event->id;
    });
    travel($explicit ? 4 : 9)->seconds();
    artisan('docket:prune')->assertSuccessful();
    expect(Run::find($run->id))->not->toBeNull()->and($ids)->toBe([]);
    travel(1)->seconds();
    artisan('docket:prune')->assertSuccessful();
    artisan('docket:prune')->assertSuccessful();
    expect(Run::find($run->id))->toBeNull()->and(Run::find($unfinished->id))->not->toBeNull()
        ->and($run->items()->count())->toBe(0)->and($run->logs()->count())->toBe(0)
        ->and($run->getConnection()->table('docket_work')->where('run_id', $run->id)->count())->toBe(0)
        ->and($run->getConnection()->table('docket_fingerprints')->where('run_id', $run->id)->count())->toBe(0)
        ->and($ids)->toBe([$run->id.':expiring']);
})->with([true, false]);

it('keeps an expiring run when a listener fails and redelivers the stable event id', function () {
    $run = Docket::expiresAt(now())->run(static fn () => null)->run();
    $ids = [];
    Event::listen(RunExpiring::class, function (RunExpiring $event) use (&$ids) {
        $ids[] = $event->id;
        throw new RuntimeException('Cleanup failed.');
    });
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(fn () => artisan('docket:prune')->run())->toThrow(RuntimeException::class, 'Cleanup failed.');
    }
    expect(Run::find($run->id))->not->toBeNull()->and($ids)->toBe([$run->id.':expiring', $run->id.':expiring']);
});

it('does not change a finished run when cancellation is requested', function (RunStatus $status) {
    $run = Run::create(['name' => 'finished', 'status' => $status]);
    $before = $run->refresh()->getAttributes();
    $run->cancel()->cancel();
    expect($run->getAttributes())->toBe($before);
})->with([RunStatus::Succeeded, RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled]);

it('keeps nonserializable inline work usable and explicitly rejects its retry', function () {
    $stream = fopen('php://memory', 'r+');
    try {
        $run = Docket::run(static function (Run $run) use ($stream) {
            fwrite($stream, 'reference:1');
            $run->cancel()->throwIfCancelled();
        })->run();
        rewind($stream);
        expect(stream_get_contents($stream))->toBe('reference:1')
            ->and($run->cancelled())->toBeTrue()
            ->and(fn () => $run->retry())->toThrow(LogicException::class, 'This run has no replayable work.');
    } finally {
        fclose($stream);
    }
});
