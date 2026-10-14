<?php

use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\AssertionFailedError;
use Tey\Docket\Docketable;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Testing\FakeRun;
use Tey\Docket\Tests\Fixtures\KeyedWork;
use Tey\Docket\Tests\Fixtures\PlainWork;

it('attaches a fresh fake to the same job without a Laravel application or database', function () {
    $original = new class
    {
        use Docketable;

        public function docketKey(): string
        {
            return 'row:1';
        }

        public function handle(): void
        {
            $this->docket()?->itemSucceeded(code: 'created');
        }
    };
    expect($original->docket())->toBeNull();
    [$job, $run] = $original->withFakeDocket();
    expect($job)->toBe($original)->and($job->docket())->toBe($run)
        ->and($run->name)->toBe($job::class)->and($run->subject)->toBeNull()->and($run->initiator)->toBeNull();
    $job->handle();
    $run->assertItemSucceeded('row:1', 'created');
    [, $fresh] = $job->withFakeDocket();
    expect($fresh)->not->toBe($run);
    expect(fn () => $fresh->assertItemSucceeded('row:1'))->toThrow(AssertionFailedError::class);
});

it('records every reporting verb and preserves earlier reports', function () {
    $run = new FakeRun;
    expect($run->step('validate')->total(3)->log('rows.staged', ['count' => 3])->result('file:1')->checkpoint(['after' => 3]))->toBe($run);
    expect($run->checkpoint())->toBe(['after' => 3]);
    $run->step('save')->total(4)->result('file:2')->checkpoint(null);
    expect($run->checkpoint())->toBeNull();
    $run->assertStep('validate');
    $run->assertStep('save');
    $run->assertTotal(3);
    $run->assertTotal(4);
    $run->assertResult('file:1');
    $run->assertResult('file:2');
    $run->assertCheckpoint(['after' => 3]);
    $run->assertCheckpoint(null);
    $run->assertLogged('rows.staged');
    $run->assertLogged('rows.staged', ['count' => 3]);
    $run->itemSucceeded('row:1', result: 9, code: 'created', count: 2)
        ->itemFailed('row:2', 'row.invalid', ['line' => 2])
        ->itemSkipped('row:3', 'row.duplicate');
    $run->assertItemSucceeded('row:1');
    $run->assertItemSucceeded('row:1', 'created');
    $run->assertItemFailed('row:2');
    $run->assertItemFailed('row:2', 'row.invalid');
    $run->assertItemSkipped('row:3');
    $run->assertItemSkipped('row:3', 'row.duplicate');
    $run->add(new PlainWork)->add(collect([[new KeyedWork('next')]]), allowRepeat: true);
    $run->assertAdded(PlainWork::class);
    $run->assertAdded(fn ($job) => $job instanceof KeyedWork && $job->label === 'next');
    expect(fn () => $run->fail('file.unsupported', ['id' => 9]))->toThrow(RunFailed::class);
    $run->assertFailed('file.unsupported');
});

it('tracks implicit successes without replacing explicit outcomes or settling breaks', function (bool $collection) {
    $run = new FakeRun;
    $run->itemSucceeded('row:1', code: 'created');
    $input = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5];
    $indexes = [];
    $iterator = $run->track($collection ? collect($input) : $input, function ($value, $index) use (&$indexes) {
        $indexes[] = $index;

        return 'row:'.$value;
    });
    $run->assertTotal(5);
    expect($indexes)->toBe(array_keys($input));
    $seen = [];
    foreach ($iterator as $key => $row) {
        $seen[] = $row;
        if ($row === 2) {
            $run->itemFailed($key, 'row.invalid');
        } elseif ($row === 3) {
            $run->itemSkipped($key, 'row.duplicate');
        } elseif ($row === 5) {
            break;
        }
    }
    unset($iterator);
    expect($seen)->toBe([2, 3, 4, 5]);
    $run->assertItemSucceeded('row:1', 'created');
    $run->assertItemFailed('row:2', 'row.invalid');
    $run->assertItemSkipped('row:3', 'row.duplicate');
    $run->assertItemSucceeded('row:4');
    expect(fn () => $run->assertItemSucceeded('row:5'))->toThrow(AssertionFailedError::class);
})->with([true, false]);

it('tracks lazy inputs on demand and keeps explicit totals', function () {
    $run = new FakeRun;
    $read = 0;
    $items = LazyCollection::make(function () use (&$read) {
        foreach (['a', 'b'] as $key) {
            $read++;
            yield $key;
        }
    });
    $iterator = $run->track($items);
    expect($read)->toBe(0)->and($run->total)->toBeNull();
    foreach ($iterator as $key) {
        break;
    }
    unset($iterator);
    expect($read)->toBe(1)->and($run->total)->toBeNull();
    expect(fn () => $run->assertItemSucceeded('a'))->toThrow(AssertionFailedError::class);
    $run->total(10);
    iterator_to_array($run->track(['a', 'b']));
    $run->assertTotal(10);
    expect($run->total)->toBe(10);
    $run->assertItemSucceeded('a');
    $run->assertItemSucceeded('b');
});

it('checks cancellation before yielding without settling unfinished items', function () {
    $run = new FakeRun;
    $run->throwIfCancelled();
    $run->cancel();
    expect(fn () => iterator_to_array($run->track(['a'])))->toThrow(RunCancelled::class);
    expect(fn () => $run->assertItemSucceeded('a'))->toThrow(AssertionFailedError::class);
});

it('fails assertions on absent reports', function (Closure $assertion) {
    expect(fn () => $assertion(new FakeRun))->toThrow(AssertionFailedError::class);
})->with([
    'step' => fn (FakeRun $run) => $run->assertStep('save'),
    'total' => fn (FakeRun $run) => $run->assertTotal(0),
    'success' => fn (FakeRun $run) => $run->assertItemSucceeded('row:1'),
    'failure' => fn (FakeRun $run) => $run->assertItemFailed('row:1'),
    'skip' => fn (FakeRun $run) => $run->assertItemSkipped('row:1'),
    'log' => fn (FakeRun $run) => $run->assertLogged('rows.staged'),
    'result' => fn (FakeRun $run) => $run->assertResult('file:1'),
    'checkpoint' => fn (FakeRun $run) => $run->assertCheckpoint(null),
    'added class' => fn (FakeRun $run) => $run->assertAdded(PlainWork::class),
    'added callback' => fn (FakeRun $run) => $run->assertAdded(fn () => true),
    'failed' => fn (FakeRun $run) => $run->assertFailed('file.unsupported'),
]);

it('fails assertions on mismatched codes params and added job predicates', function (Closure $assertion) {
    $run = new FakeRun;
    $run->itemSucceeded('a', code: 'created')->itemFailed('b', 'row.invalid')->itemSkipped('c', 'row.duplicate')
        ->log('rows.staged', ['count' => 3])->add(new PlainWork);
    expect(fn () => $assertion($run))->toThrow(AssertionFailedError::class);
})->with([
    'success code' => fn (FakeRun $run) => $run->assertItemSucceeded('a', 'updated'),
    'failure code' => fn (FakeRun $run) => $run->assertItemFailed('b', 'row.other'),
    'skip code' => fn (FakeRun $run) => $run->assertItemSkipped('c', 'row.other'),
    'wrong outcome' => fn (FakeRun $run) => $run->assertItemSucceeded('b'),
    'log params' => fn (FakeRun $run) => $run->assertLogged('rows.staged', ['count' => '3']),
    'added job' => fn (FakeRun $run) => $run->assertAdded(KeyedWork::class),
    'added predicate' => fn (FakeRun $run) => $run->assertAdded(fn () => false),
]);

it('rejects invalid reports rather than making invalid production code pass', function (Closure $report) {
    expect(fn () => $report(new FakeRun))->toThrow(InvalidArgumentException::class);
})->with([
    'step' => fn (FakeRun $run) => $run->step(str_repeat('a', 256)),
    'total' => fn (FakeRun $run) => $run->total(-1),
    'missing item key' => fn (FakeRun $run) => $run->itemSucceeded(),
    'invalid track key' => fn (FakeRun $run) => $run->track(['']),
    'result content' => fn (FakeRun $run) => $run->itemSucceeded('a', result: []),
    'count' => fn (FakeRun $run) => $run->itemSucceeded('a', count: 0),
    'success code' => fn (FakeRun $run) => $run->itemSucceeded('a', code: 'run.failed'),
    'missing failure code' => fn (FakeRun $run) => $run->itemFailed('a'),
    'missing skip code' => fn (FakeRun $run) => $run->itemSkipped('a'),
    'failure params' => fn (FakeRun $run) => $run->itemFailed('a', 'row.invalid', ['nested' => []]),
    'skip params' => fn (FakeRun $run) => $run->itemSkipped('a', 'row.duplicate', ['nested' => []]),
    'log code' => fn (FakeRun $run) => $run->log('run.failed'),
    'log params' => fn (FakeRun $run) => $run->log('rows.staged', ['nested' => []]),
    'checkpoint' => fn (FakeRun $run) => $run->checkpoint(['nested' => []]),
    'failure code' => fn (FakeRun $run) => $run->fail('run.failed'),
    'failure params on run' => fn (FakeRun $run) => $run->fail('file.unsupported', ['nested' => []]),
]);
