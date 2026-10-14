<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Facades\Docket;
use Tey\Docket\ItemStatus;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

beforeEach(function () {
    actingAs(new User(['id' => 1]));
});

it('returns the exact snapshot and state-dependent links for the initiator', function (RunStatus $status) {
    $run = Run::create([
        'name' => 'export.build', 'status' => $status,
        'initiator_type' => User::class, 'initiator_id' => '1',
        'checkpoint' => 'private-checkpoint', 'replay' => 'private-replay',
        'finished_at' => $status->isFinished() ? now() : null,
    ]);
    $response = get('/docket/runs/'.$run->token)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(json_decode($run->toJson(), true));
    expect($response->getContent())->not->toContain('private-checkpoint', 'private-replay')
        ->and($response->json('links.cancel') !== null)->toBe(! $status->isFinished())
        ->and($response->json('links.retry') !== null)->toBe(in_array($status, [RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled], true));
})->with(RunStatus::cases());

it('makes unknown expired unviewable and scheduled runs indistinguishable on every route', function (string $method, string $suffix) {
    $hidden = Run::create(['name' => 'hidden', 'initiator_type' => User::class, 'initiator_id' => '2']);
    $expired = Run::create(['name' => 'expired', 'initiator_type' => User::class, 'initiator_id' => '1', 'expires_at' => now()->subSecond()]);
    $scheduled = Run::create(['name' => 'scheduled']);
    $bodies = [];
    foreach (['unknown', $hidden->token, $expired->token, $scheduled->token] as $token) {
        $response = json($method, '/docket/runs/'.$token.$suffix)
            ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $bodies[] = $response->getContent();
    }
    expect(array_unique($bodies))->toHaveCount(1)
        ->and($hidden->refresh()->cancel_requested)->toBeFalse()
        ->and(Run::count())->toBe(3);
})->with([
    ['GET', ''], ['GET', '/items'], ['GET', '/logs'], ['GET', '/children'],
    ['POST', '/cancel'], ['POST', '/retry'],
]);

it('denies guests and matching ids from another morph type and supports morph aliases', function () {
    Relation::morphMap(['member' => User::class]);
    try {
        $run = Run::create(['name' => 'owned', 'initiator_type' => 'member', 'initiator_id' => '1']);
        getJson('/docket/runs/'.$run->token)->assertOk();
        $run->update(['initiator_type' => 'another-type']);
        getJson('/docket/runs/'.$run->token)->assertNotFound();
        $run->update(['initiator_type' => 'member']);
        auth()->forgetGuards();
        getJson('/docket/runs/'.$run->token)->assertNotFound();
    } finally {
        Relation::morphMap([], false);
    }
});

it('honours a replacement policy and rechecks revoked access before throttling', function () {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $run = Run::create(['name' => 'scheduled']);
    getJson('/docket/runs/'.$run->token)->assertOk()->assertJsonMissingPath('links.cancel');
    postJson('/docket/runs/'.$run->token.'/cancel')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    postJson('/docket/runs/'.$run->token.'/retry')->assertForbidden();
    $run->update(['name' => 'hidden']);
    getJson('/docket/runs/'.$run->token)->assertNotFound();
    getJson('/docket/runs/'.$run->token.'/items')->assertNotFound();
});

it('limits snapshot polling per viewer and run with Retry-After without blocking related pages', function () {
    freezeTime();
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    config(['docket.poll_after_ms' => 2500]);
    $run = Run::create(['name' => 'poll']);
    getJson('/docket/runs/'.$run->token)->assertOk();
    getJson('/docket/runs/'.$run->token)->assertStatus(429)->assertHeader('Retry-After', '3')
        ->assertHeader('Cache-Control', 'no-store, private');
    getJson('/docket/runs/'.$run->token.'/logs')->assertOk();
    actingAs(new User(['id' => 2]));
    getJson('/docket/runs/'.$run->token)->assertOk();
    actingAs(new User(['id' => 1]));
    travel(3)->seconds();
    getJson('/docket/runs/'.$run->token)->assertOk();
});

it('requests cancellation once and returns the fresh snapshot', function () {
    $run = Run::create(['name' => 'cancel', 'initiator_type' => User::class, 'initiator_id' => '1']);
    postJson('/docket/runs/'.$run->token.'/cancel')->assertOk()->assertJsonPath('cancel_requested', true)
        ->assertHeader('Cache-Control', 'no-store, private');
    $revision = $run->refresh()->revision;
    postJson('/docket/runs/'.$run->token.'/cancel')->assertOk()->assertJsonPath('revision', $revision);
    expect($run->refresh()->status)->toBe(RunStatus::Queued);
});

it('rejects controls that conflict with the run status', function (RunStatus $status) {
    $run = Run::create(['name' => 'control', 'status' => $status, 'initiator_type' => User::class, 'initiator_id' => '1']);
    if ($status->isFinished()) {
        postJson('/docket/runs/'.$run->token.'/cancel')->assertConflict()->assertHeader('Cache-Control', 'no-store, private');
    }
    if (! $status->isFinished() || $status === RunStatus::Succeeded) {
        postJson('/docket/runs/'.$run->token.'/retry')->assertConflict();
    }
})->with(RunStatus::cases());

it('retries retained work as a new 202 ticket without accepting work from the request', function (RunStatus $status) {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    actingAs(User::create());
    Queue::fake();
    $run = Docket::job(static fn () => null)->dispatch()->run();
    $run->update(['status' => $status, 'finished_at' => now()]);
    $response = postJson('/docket/runs/'.$run->token.'/retry', ['job' => 'untrusted', 'initiator_id' => 99])
        ->assertAccepted()->assertHeader('Cache-Control', 'no-store, private');
    $retry = Run::findOrFail($response->json('id'));
    $response->assertExactJson($retry->ticket()->toArray())->assertHeader('Location', $retry->ticket()->toArray()['links']['poll']);
    expect($retry->retry_of_run_id)->toBe($run->id)
        ->and($retry->initiator_id)->toBe('1')
        ->and($retry->token)->not->toBe($run->token);
})->with([RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled]);

it('returns a conflict when finished work cannot be replayed', function () {
    $run = Run::create(['name' => 'unreplayable', 'status' => RunStatus::Failed, 'initiator_type' => User::class, 'initiator_id' => '1']);
    postJson('/docket/runs/'.$run->token.'/retry')->assertConflict();
    expect(Run::count())->toBe(1);
});

it('returns exact item fields and filters every documented outcome', function (ItemStatus $status) {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $run = Run::create(['name' => 'items']);
    foreach (ItemStatus::cases() as $outcome) {
        $run->items()->create(['key' => $outcome->value, 'status' => $outcome, 'count' => 2]);
    }
    $response = getJson('/docket/runs/'.$run->token.'/items?status='.$status->value)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [[
            'key' => $status->value, 'status' => $status->value, 'code' => null,
            'params' => [], 'result' => null, 'count' => 2, 'finished_at' => null,
        ]], 'links' => ['next' => null, 'prev' => null]]);
    expect(json_decode($response->getContent())->data[0]->params)->toBeInstanceOf(stdClass::class);
})->with(ItemStatus::cases());

it('cursor paginates items and logs with only documented fields and stable next and previous links', function (string $relation) {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $run = Run::create(['name' => 'pages']);
    foreach (range(1, 101) as $i) {
        $run->{$relation}()->create($relation === 'items'
            ? ['key' => 'row:'.$i, 'status' => 'failed', 'code' => 'row.invalid', 'params' => ['row' => $i], 'finished_at' => '2026-10-13 14:02:15']
            : ['code' => 'rows.staged', 'params' => ['count' => $i], 'created_at' => '2026-10-13 14:02:15']);
    }
    $query = $relation === 'items' ? '?status=failed&per_page=1000' : '?per_page=1000';
    $first = getJson('/docket/runs/'.$run->token.'/'.$relation.$query)->assertOk()->assertJsonCount(100, 'data');
    $second = getJson($first->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('links.next', null);
    getJson($second->json('links.prev'))->assertOk()->assertExactJson($first->json());
    if ($relation === 'logs') {
        $second->assertJsonPath('data.0', ['code' => 'rows.staged', 'params' => ['count' => 101], 'created_at' => '2026-10-13T14:02:15Z']);
    } else {
        $second->assertJsonPath('data.0.key', 'row:101')->assertJsonPath('data.0.finished_at', '2026-10-13T14:02:15Z');
        expect($first->json('links.next'))->toContain('status=failed');
    }
})->with(['items', 'logs']);

it('pages only visible direct children oldest first with ticket extensions and visible cursor boundaries', function () {
    freezeTime();
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $root = Run::create(['name' => 'root', 'children_total' => 203]);
    $ids = [];
    foreach (range(1, 101) as $i) {
        $root->children()->create(['name' => 'hidden', 'status' => 'running']);
        $child = $root->children()->create(['name' => 'visible', 'status' => 'running', 'checkpoint' => 'private-checkpoint']);
        $ids[] = $child->id;
    }
    $root->children()->create(['name' => 'expired', 'expires_at' => now()->subSecond()]);
    $child->children()->create(['name' => 'grandchild']);
    $child->logs()->create(['code' => 'older', 'created_at' => now()->subMinute()]);
    $child->logs()->create(['code' => 'latest', 'params' => ['count' => 5]]);
    $first = getJson('/docket/runs/'.$root->token.'/children?status=running')->assertOk()->assertJsonCount(100, 'data');
    expect(array_column($first->json('data'), 'id'))->toBe(array_slice($ids, 0, 100));
    $second = getJson($first->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('links.next', null);
    expect($second->json('data.0.id'))->toBe($child->id);
    getJson($second->json('links.prev'))->assertOk()->assertExactJson($first->json());
    $expected = [...$child->ticket()->toArray(),
        'step' => null, 'progress' => ['done' => 0, 'total' => null, 'percent' => null],
        'counts' => ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
        'children' => ['total' => 0, 'running' => 0, 'succeeded' => 0, 'partial' => 0, 'failed' => 0],
        'latest_log' => ['code' => 'latest', 'params' => ['count' => 5], 'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
    ];
    $expected['links']['logs'] = $expected['links']['poll'].'/logs';
    $second->assertJsonPath('data.0', $expected);
    expect($second->getContent())->not->toContain('private-checkpoint', 'grandchild', 'hidden');
    getJson('/docket/runs/'.$root->token)->assertJsonPath('children.total', 203);
});

it('returns empty pages and rejects invalid status filters without exposing data', function () {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $run = Run::create(['name' => 'empty']);
    foreach (['items', 'logs', 'children'] as $page) {
        getJson('/docket/runs/'.$run->token.'/'.$page)->assertExactJson(['data' => [], 'links' => ['next' => null, 'prev' => null]]);
    }
    foreach (['items', 'children'] as $page) {
        getJson('/docket/runs/'.$run->token.'/'.$page.'?status=invalid')->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
});

it('rejects malformed cursors on every page', function (string $cursor) {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $run = Run::create(['name' => 'cursor']);
    foreach (['items', 'logs', 'children'] as $page) {
        getJson('/docket/runs/'.$run->token.'/'.$page.'?cursor='.urlencode($cursor))
            ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    }
})->with(['invalid', 'e30=', 'bnVsbA==', base64_encode('{"_pointsToNextItems":true,"id":[]}')]);

it('filters every child status and resolves each child action link without exposing expired children', function (RunStatus $status) {
    freezeTime();
    $owner = ['initiator_type' => User::class, 'initiator_id' => '1'];
    $root = Run::create(['name' => 'root', ...$owner]);
    foreach (RunStatus::cases() as $outcome) {
        $root->children()->create(['name' => $outcome->value, 'status' => $outcome, ...$owner]);
    }
    $root->children()->create(['name' => 'expired', 'status' => $status, 'expires_at' => now(), ...$owner]);
    $root->children()->create(['name' => 'hidden', 'status' => $status, 'initiator_type' => User::class, 'initiator_id' => '2']);
    $response = getJson('/docket/runs/'.$root->token.'/children?status='.$status->value)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $status->value)
        ->assertJsonPath('data.0.latest_log', null);
    expect($response->json('data.0.links.cancel') !== null)->toBe(! $status->isFinished())
        ->and($response->json('data.0.links.retry') !== null)->toBe(in_array($status, [RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled], true));
})->with(RunStatus::cases());

it('keeps the retry endpoint at 202 even when the configured sync queue finishes immediately', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    actingAs(User::create());
    config(['queue.default' => 'sync']);
    $run = Docket::run('replay', static fn () => null)->run();
    $run->update(['status' => RunStatus::Failed]);
    postJson('/docket/runs/'.$run->token.'/retry')->assertAccepted()->assertJsonPath('status', 'succeeded');
});
