<?php

use Illuminate\Support\Facades\Gate;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

use function Pest\Laravel\freezeTime;

it('serializes the exact v1 snapshot and never columns or loaded relations', function () {
    freezeTime();
    $run = Run::create([
        'name' => 'rows.import', 'status' => RunStatus::Running, 'step' => 'validate', 'done' => 340, 'total' => 1200,
        'succeeded_count' => 6, 'failed_count' => 3, 'skipped_count' => 1,
        'checkpoint' => ['cursor' => 'private-position'],
    ]);
    $run->logs()->create(['code' => 'private.log', 'params' => ['id' => 41]]);
    $run->load(['items', 'logs', 'subject', 'initiator']);
    $poll = 'http://localhost/docket/runs/'.$run->token;

    $expected = [
        'schema_version' => 1, 'id' => $run->id, 'token' => $run->token, 'name' => 'rows.import', 'status' => 'running',
        'revision' => 1, 'step' => 'validate', 'progress' => ['done' => 340, 'total' => 1200, 'percent' => 28],
        'counts' => ['processed' => 10, 'succeeded' => 6, 'failed' => 3, 'skipped' => 1],
        'error' => null, 'result' => null, 'partial' => [], 'cancel_requested' => false,
        'parent_run_id' => null, 'root_run_id' => null, 'retry_of_run_id' => null, 'linked_from_run_id' => null,
        'children' => ['total' => 0, 'running' => 0, 'succeeded' => 0, 'partial' => 0, 'failed' => 0],
        'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'finished_at' => null, 'expires_at' => null,
        'poll_after_ms' => 2000, 'links' => ['poll' => $poll, 'items' => $poll.'/items', 'logs' => $poll.'/logs'],
    ];

    expect($run->toArray())->toBe($expected)
        ->and($run->jsonSerialize())->toBe($expected)
        ->and(json_decode($run->toJson(), true))->toBe($expected)
        ->and(response()->json($run)->getData(true))->toBe($expected);
});

it('returns results in finish order and only the newest twenty references in partial', function () {
    $run = Run::create(['name' => 'export.build']);
    $other = Run::create(['name' => 'other.work']);
    $other->items()->create(['key' => 'other', 'result' => 'private-reference']);
    $run->items()->create(['key' => 'no-result']);

    foreach (array_reverse(range(1, 23)) as $number) {
        $run->items()->create([
            'key' => 'part:'.$number, 'status' => 'succeeded', 'result' => 'file:'.$number,
            'finished_at' => now()->startOfDay()->addSeconds($number),
        ]);
    }

    $results = [];
    $partial = [];
    foreach (range(1, 23) as $number) {
        $results['part:'.$number] = 'file:'.$number;
    }
    foreach (range(23, 4) as $number) {
        $partial[] = ['key' => 'part:'.$number, 'result' => 'file:'.$number];
    }

    expect($run->results()->all())->toBe($results)
        ->and($run->toArray()['partial'])->toBe($partial)
        ->and($run->finished())->toBeFalse();
});

it('orders simultaneous item results deterministically', function () {
    $run = Run::create(['name' => 'export.build']);
    $finished = now();
    $run->items()->create(['key' => 'part:2', 'result' => 'file:2', 'finished_at' => $finished]);
    $run->items()->create(['key' => 'part:1', 'result' => 'file:1', 'finished_at' => $finished]);

    expect($run->results()->all())->toBe(['part:2' => 'file:2', 'part:1' => 'file:1'])
        ->and($run->toArray()['partial'])->toBe([
            ['key' => 'part:1', 'result' => 'file:1'], ['key' => 'part:2', 'result' => 'file:2'],
        ]);
});

it('normalizes timestamps to UTC, preserves error params as objects and ends polling', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('America/Toronto');

    try {
        $run = Run::create([
            'name' => 'file.convert', 'status' => 'failed', 'error' => ['code' => 'file.unsupported', 'params' => []],
            'created_at' => '2026-10-13 10:02:11', 'finished_at' => '2026-10-13 10:03:40', 'expires_at' => '2026-10-14 10:03:40',
            'result' => 'file:7', 'cancel_requested' => true,
        ]);
        $snapshot = json_decode($run->toJson());
    } finally {
        date_default_timezone_set($timezone);
    }

    expect($snapshot->created_at)->toBe('2026-10-13T14:02:11Z')
        ->and($snapshot->finished_at)->toBe('2026-10-13T14:03:40Z')
        ->and($snapshot->expires_at)->toBe('2026-10-14T14:03:40Z')
        ->and($snapshot->error->params)->toBeInstanceOf(stdClass::class)
        ->and($snapshot->error->code)->toBe('file.unsupported')
        ->and($snapshot->result)->toBe('file:7')
        ->and($snapshot->cancel_requested)->toBeTrue()
        ->and($snapshot->poll_after_ms)->toBeNull();

    $run->error = ['code' => 'row.invalid', 'params' => ['row' => 10]];
    expect(json_decode($run->toJson())->error->params->row)->toBe(10);
});

it('includes action links only for allowed viewers and applicable statuses', function (RunStatus $status) {
    Gate::policy(Run::class, stdClass::class);
    $run = Run::create(['name' => 'rows.import', 'status' => $status]);
    config(['docket.routes.prefix' => 'api/work', 'docket.poll_after_ms' => 3500]);
    Gate::define('cancel', fn (?object $user, Run $run) => true);
    Gate::define('retry', fn (?object $user, Run $run) => true);
    $links = $run->toArray()['links'];

    expect(isset($links['cancel']))->toBe(! $status->isFinished())
        ->and(isset($links['retry']))->toBe(in_array($status, [RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled], true))
        ->and($links['poll'])->toBe('http://localhost/api/work/runs/'.$run->token)
        ->and($run->toArray()['poll_after_ms'])->toBe($status->isFinished() ? null : 3500);

    Gate::define('cancel', fn (?object $user, Run $run) => false);
    Gate::define('retry', fn (?object $user, Run $run) => false);
    expect($run->toArray()['links'])->not->toHaveKeys(['cancel', 'retry', 'children']);
})->with(RunStatus::cases());

it('exposes stored child counters and lineage without child content', function () {
    $root = Run::create(['name' => 'catalog.sync']);
    $run = Run::create([
        'name' => 'catalog.fetch', 'parent_run_id' => $root->id, 'root_run_id' => $root->id,
        'retry_of_run_id' => $root->id, 'linked_from_run_id' => $root->id,
        'children_total' => 7, 'children_running' => 3, 'children_succeeded' => 1, 'children_partial' => 1, 'children_failed' => 1,
        'done' => 67, 'total' => 101,
    ]);
    $run->load(['parent', 'root', 'retryOf', 'linkedFrom', 'children']);
    $snapshot = $run->toArray();

    expect($snapshot['children'])->toBe(['total' => 7, 'running' => 3, 'succeeded' => 1, 'partial' => 1, 'failed' => 1])
        ->and($snapshot['parent_run_id'])->toBe($root->id)
        ->and($snapshot['root_run_id'])->toBe($root->id)
        ->and($snapshot['retry_of_run_id'])->toBe($root->id)
        ->and($snapshot['linked_from_run_id'])->toBe($root->id)
        ->and($snapshot['progress'])->toBe(['done' => 67, 'total' => 101, 'percent' => 66])
        ->and($snapshot['links']['children'])->toBe('http://localhost/docket/runs/'.$run->token.'/children');
});
