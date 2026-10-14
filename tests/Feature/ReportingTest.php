<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\LazyCollection;
use Tey\Docket\Events\ItemFinished;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\Facades\Docket;
use Tey\Docket\ItemStatus;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

use function Pest\Laravel\travel;

it('reports progress logs references and a private resumable checkpoint', function () {
    $ticket = Docket::run('rows.import', function (Run $run) {
        expect($run->step('validate')->total(52)->log('rows.staged', ['count' => 52, 'valid' => true, 'cursor' => null])
            ->result('file:9')->checkpoint(['after' => 50]))->toBe($run);
        expect($run->checkpoint())->toBe(['after' => 50]);
        $run->itemSucceeded('part:1', result: 123, code: 'created', count: 50);
        $run->itemFailed('row:51', code: 'row.invalid', params: ['line' => 51]);
        $run->itemSkipped('row:52', code: 'row.duplicate');
    });
    $run = $ticket->run();
    expect($run->status)->toBe(RunStatus::Partial)
        ->and($run->toArray()['progress'])->toBe(['done' => 52, 'total' => 52, 'percent' => 100])
        ->and($run->toArray()['counts'])->toBe(['processed' => 3, 'succeeded' => 1, 'failed' => 1, 'skipped' => 1])
        ->and($run->toArray()['partial'])->toBe([['key' => 'part:1', 'result' => '123']])
        ->and($run->results()->all())->toBe(['part:1' => '123'])
        ->and($run->step)->toBe('validate')->and($run->result)->toBe('file:9')
        ->and($run->logs()->first()->params)->toBe(['count' => 52, 'valid' => true, 'cursor' => null])
        ->and($run->toArray())->not->toHaveKey('checkpoint')->and($ticket->toArray())->not->toHaveKey('checkpoint');
});

it('declares eager items immediately and settles only when iteration advances', function (bool $collection) {
    $run = Run::create(['name' => 'rows']);
    $items = ['first' => 8, 'second' => 9, 'third' => 10];
    $seen = [];
    $iterator = $run->track($collection ? collect($items) : $items, function ($item, $index) use (&$seen) {
        $seen[] = $index;

        return 'row:'.$item;
    });
    expect($run->total)->toBe(3)->and($run->items()->pending()->count())->toBe(3)
        ->and($seen)->toBe(['first', 'second', 'third']);
    foreach ($iterator as $key => $row) {
        if ($row === 8) {
            $run->itemSkipped($key, code: 'row.duplicate');

            continue;
        }
        break;
    }
    unset($iterator);
    expect($run->items()->where('key', 'row:8')->first()->status)->toBe(ItemStatus::Skipped)
        ->and($run->items()->pending()->count())->toBe(2)->and($run->refresh()->done)->toBe(1);
    $run->track(['step:build', 'step:deploy']);
    expect($run->items()->pending()->count())->toBe(4)->and($run->total)->toBe(5);
})->with([true, false]);

it('tracks lazy inputs without exhausting them or inventing a total', function () {
    $run = Run::create(['name' => 'lazy']);
    $read = 0;
    $items = LazyCollection::make(function () use (&$read) {
        foreach (['a', 'b', 'c'] as $key) {
            $read++;
            yield $key;
        }
    });
    $iterator = $run->track($items);
    expect($read)->toBe(0)->and($run->items()->count())->toBe(0);
    foreach ($iterator as $key => $item) {
        if ($key === 'b') {
            break;
        }
    }
    expect($read)->toBe(2)->and($run->items()->count())->toBe(2)
        ->and($run->refresh()->done)->toBe(1)->and($run->progress->percent)->toBeNull();
    $run->total(10);
    iterator_to_array($run->track(['d', 'e']));
    expect($run->total)->toBe(10)->and($run->done)->toBe(3);
});

it('skips succeeded keys in this run and its retry source without double counting', function () {
    Event::fake([ItemFinished::class]);
    $previous = Run::create(['name' => 'previous']);
    $previous->itemSucceeded('a', result: 'file:1', count: 3)->itemFailed('b', code: 'row.invalid');
    $run = Run::create(['name' => 'retry', 'retry_of_run_id' => $previous->id]);
    $run->total(5);
    expect(iterator_to_array($run->track(['a', 'b', 'c', 'c'])))->toBe(['b' => 'b', 'c' => 'c']);
    $stale = Run::findOrFail($run->id);
    $run->itemSucceeded('c', count: 99)->itemFailed('c', code: 'row.invalid');
    $stale->itemSucceeded('c', count: 99);
    expect($run->refresh()->done)->toBe(5)->and($run->succeeded_count)->toBe(3)
        ->and($run->results()->all())->toBe(['a' => 'file:1']);
    Event::assertDispatchedTimes(ItemFinished::class, 5);
});

it('fires stable item events only after commit and rolls back reporting atomically', function () {
    Event::fake([ItemFinished::class]);
    $run = Run::create(['name' => 'atomic']);
    $connection = $run->getConnection();
    $connection->beginTransaction();
    $run->itemSucceeded('a');
    Event::assertNotDispatched(ItemFinished::class);
    $connection->rollBack();
    expect($run->refresh()->done)->toBe(0)->and($run->items()->count())->toBe(0);
    $connection->beginTransaction();
    $run->itemSucceeded('a');
    $connection->commit();
    Event::assertDispatched(ItemFinished::class, fn ($event) => $event->id === (new ItemFinished($event->run, $event->item))->id && $event->run->done === 1);
    $run->itemSucceeded('a');
    Event::assertDispatchedTimes(ItemFinished::class, 1);
});

it('checks fresh cancellation before yielding and keeps completed work', function () {
    $ticket = Docket::run(function (Run $run) {
        foreach ($run->track(['a', 'b']) as $key) {
            expect($key)->toBe('a');
            Run::findOrFail($run->id)->update(['cancel_requested' => true]);
        }
        throw new LogicException('Cancellation must stop the loop.');
    });
    expect($ticket->status)->toBe(RunStatus::Cancelled)->and($ticket->run()->done)->toBe(1)
        ->and($ticket->run()->items()->pending()->count())->toBe(1)
        ->and(fn () => $ticket->run()->throwIfCancelled())->toThrow(RunCancelled::class);
});

it('leaves the current item pending when the loop body throws', function () {
    expect(fn () => Docket::run(function (Run $run) {
        foreach ($run->track(['a', 'b']) as $key) {
            throw new RuntimeException('private');
        }
    }))->toThrow(RuntimeException::class);
    expect(Run::first()->items()->pending()->count())->toBe(2)->and(Run::first()->done)->toBe(0);
});

it('bounds the partial preview while preserving all item references', function () {
    $run = Run::create(['name' => 'parts']);
    foreach (range(1, 25) as $part) {
        $run->itemSucceeded('part:'.$part, result: 'file:'.$part);
    }
    expect($run->toArray()['partial'])->toHaveCount(20)
        ->and($run->toArray()['partial'][0])->toBe(['key' => 'part:25', 'result' => 'file:25'])
        ->and($run->results())->toHaveCount(25);
});

it('validates reporting structure before writing', function (Closure $report) {
    $run = Run::create(['name' => 'validation']);
    expect(fn () => $report($run))->toThrow(InvalidArgumentException::class);
    expect($run->refresh()->revision)->toBe(1)->and($run->items()->count())->toBe(0)
        ->and($run->logs()->count())->toBe(0)->and($run->checkpoint())->toBeNull();
})->with([
    'negative total' => [fn (Run $run) => $run->total(-1)],
    'zero count' => [fn (Run $run) => $run->itemSucceeded('a', count: 0)],
    'missing key' => [fn (Run $run) => $run->itemSucceeded()],
    'missing code' => [fn (Run $run) => $run->itemFailed('a')],
    'reserved code' => [fn (Run $run) => $run->log('run.failed')],
    'long step' => [fn (Run $run) => $run->step(str_repeat('a', 256))],
    'invalid key' => [fn (Run $run) => $run->track([new stdClass])],
    'content result' => [fn (Run $run) => $run->itemSucceeded('a', result: ['content'])],
    'nested params' => [fn (Run $run) => $run->log('rows.staged', ['nested' => ['id' => 1]])],
    'long params' => [fn (Run $run) => $run->log('rows.staged', ['id' => str_repeat('a', 4096)])],
    'too many params' => [fn (Run $run) => $run->log('rows.staged', array_combine(array_map(fn ($i) => 'k'.$i, range(1, 21)), range(1, 21)))],
    'nested checkpoint' => [fn (Run $run) => $run->checkpoint(['nested' => []])],
    'object checkpoint' => [fn (Run $run) => $run->checkpoint(new stdClass)],
    'long checkpoint' => [fn (Run $run) => $run->checkpoint(str_repeat('a', 4096))],
    'non-finite checkpoint' => [fn (Run $run) => $run->checkpoint(INF)],
]);

it('can clear a checkpoint and refreshes the revision and report timestamp', function () {
    $run = Run::create(['name' => 'heartbeat']);
    travel(10)->seconds();
    $run->checkpoint(50);
    expect($run->checkpoint())->toBe(50)->and($run->revision)->toBeGreaterThan(1)
        ->and($run->updated_at->greaterThan($run->created_at))->toBeTrue();
    $revision = $run->revision;
    $run->checkpoint(null);
    expect($run->checkpoint())->toBeNull()->and($run->revision)->toBeGreaterThan($revision);
});
