<?php

use Illuminate\Support\Facades\Gate;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

it('unwinds nested inline work on return exception and explicit failure', function (string $exit) {
    Docket::run('parent', function (Run $parent) use ($exit) {
        try {
            Docket::run('child', function (Run $child) use ($exit) {
                expect(Docket::current())->toBe($child);
                if ($exit === 'exception') {
                    throw new RuntimeException('private');
                }
                if ($exit === 'fail') {
                    $child->fail('work.stopped');
                }
            });
            expect($exit)->toBe('return');
        } catch (RuntimeException $exception) {
            expect($exception)->toBeInstanceOf($exit === 'fail' ? RunFailed::class : RuntimeException::class);
        }
        expect(Docket::current())->toBe($parent);
    });
    expect(Docket::current())->toBeNull();
})->with(['return', 'exception', 'fail']);

it('releases inline results and runs when their tickets leave scope', function () {
    $result = new stdClass;
    $reference = WeakReference::create($result);
    $ticket = Docket::run(fn () => $result);
    expect($ticket->result())->toBe($result);
    unset($ticket, $result);
    gc_collect_cycles();
    expect($reference->get())->toBeNull()->and(Docket::current())->toBeNull();
});

it('bounds progress queries linearly at depths one five and ten', function (int $depth) {
    $descend = function (int $level) use (&$descend, $depth): void {
        Docket::total(10)->run('level.'.$level, function (Run $run) use ($level, $depth, &$descend) {
            if ($level < $depth) {
                $descend($level + 1);

                return;
            }
            $connection = $run->getConnection();
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $run->itemSucceeded('row:1', count: 2);
            $queries = collect($connection->getQueryLog())->pluck('query');
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            expect($queries->filter(fn (string $sql) => str_starts_with($sql, 'update "docket_runs"'))->count())->toBe($depth + 1)
                ->and($queries->count())->toBeLessThanOrEqual(12 + 3 * $depth)
                ->and($run->root()->firstOrFail()->progress->done)->toBe(2);
        });
    };
    $descend(0);
    unset($descend);
})->with([1, 5, 10]);

it('fetches latest tree logs in one query regardless of tree width', function (int $width) {
    Gate::policy(Run::class, stdClass::class);
    Gate::define('view', fn (?object $user, Run $run) => $run->name !== 'hidden');
    $root = Run::create(['name' => 'root']);
    foreach (range(1, $width) as $index) {
        $child = Run::create(['name' => 'child.'.$index, 'parent_run_id' => $root->id]);
        $child->logs()->create(['code' => 'old']);
        $child->logs()->create(['code' => 'latest']);
    }
    $hidden = Run::create(['name' => 'hidden', 'parent_run_id' => $root->id]);
    $hidden->logs()->create(['code' => 'private']);
    Run::create(['name' => 'hidden.descendant', 'parent_run_id' => $hidden->id]);
    $connection = $root->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $tree = $root->tree();
    $queries = collect($connection->getQueryLog())->pluck('query');
    $connection->disableQueryLog();
    $connection->flushQueryLog();
    expect($queries->filter(fn (string $sql) => str_contains($sql, '"docket_logs"'))->count())->toBe(1)
        ->and($tree['latest_log'])->toBeNull()
        ->and($tree['children'])->toHaveCount($width)
        ->and(json_encode($tree))->not->toContain('private', 'hidden');
    foreach ($tree['children'] as $child) {
        expect($child['latest_log']['code'])->toBe('latest');
    }
})->with([1, 25]);

it('loads the serialization run once without caching permissions or serializing private state', function () {
    Gate::policy(Run::class, stdClass::class);
    $allowed = true;
    Gate::define('cancel', function (?object $user, Run $run) use (&$allowed) {
        return $allowed;
    });
    $run = Run::create(['name' => 'work', 'checkpoint' => 'private-position']);
    $ticket = $run->ticket()->withResult('private-result');
    $connection = $run->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    expect($ticket->toArray()['links'])->toHaveKey('cancel');
    $allowed = false;
    expect($ticket->jsonSerialize()['links'])->not->toHaveKey('cancel');
    $serialized = serialize($ticket);
    expect($connection->getQueryLog())->toHaveCount(1)
        ->and($serialized)->not->toContain('private-', 'serializationRun');
    $connection->disableQueryLog();
    $connection->flushQueryLog();
    $run->update(['status' => RunStatus::Failed]);
    expect($ticket->run()->status)->toBe(RunStatus::Failed);
    $connection->enableQueryLog();
    unserialize($serialized)->toArray();
    expect($connection->getQueryLog())->toHaveCount(1);
    $connection->disableQueryLog();
    $connection->flushQueryLog();
});
