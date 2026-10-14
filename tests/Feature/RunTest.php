<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Item;
use Tey\Docket\ItemStatus;
use Tey\Docket\Log;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\Document;
use Tey\Docket\Tests\Fixtures\Upload;
use Tey\Docket\Tests\Fixtures\User;

it('finds stored runs by token without checking access or expiry', function (RunStatus $status) {
    $run = Run::create([
        'name' => 'rows.import',
        'status' => $status,
        'expires_at' => now()->subDay(),
    ]);
    Run::create(['name' => 'rows.import']);

    expect(Gate::allows('view', $run))->toBeFalse();

    $found = Docket::find($run->token);

    expect($found?->is($run))->toBeTrue()
        ->and($found?->status)->toBe($status);
})->with(RunStatus::cases());

it('returns null for missing tokens rather than looking up ids or names', function () {
    $run = Run::create(['name' => 'rows.import']);

    expect(Docket::find('missing'))->toBeNull()
        ->and(Docket::find(''))->toBeNull()
        ->and(Docket::find($run->id))->toBeNull()
        ->and(Docket::find($run->name))->toBeNull();

    $run->delete();

    expect(Docket::find($run->token))->toBeNull();
});

it('creates ULIDs and independent cryptographically random tokens with database uniqueness', function () {
    $first = Run::create(['name' => 'rows.import']);
    $second = Run::create(['name' => 'rows.import']);

    expect(Str::isUlid($first->id))->toBeTrue()
        ->and($first->token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second->token)->not->toBe($first->token)
        ->and($first->status)->toBe(RunStatus::Queued)
        ->and($first->revision)->toBe(1)
        ->and($first->progress->toArray())->toBe(['done' => 0, 'total' => null, 'percent' => null]);

    $second->token = $first->token;
    expect(fn () => $second->save())->toThrow(QueryException::class);
});

it('advances the revision on changes but not unchanged saves', function () {
    $run = Run::create(['name' => 'rows.import']);
    $stale = $run->fresh();
    $run->update(['step' => 'validate']);
    $run->save();

    expect($run->fresh()->revision)->toBe(2);

    $stale->update(['done' => 4, 'cancel_requested' => true, 'checkpoint' => ['page' => 3]]);
    expect($run->fresh()->revision)->toBe(3)
        ->and($run->fresh()->checkpoint)->toBe(['page' => 3])
        ->and($stale->cancelRequested())->toBeTrue()
        ->and($stale->revision)->toBe(3);
});

it('reads every run status through enums, status checks and scopes', function (RunStatus $status, bool $finished) {
    $run = Run::create(['name' => 'rows.import', 'status' => $status]);
    Run::create(['name' => 'other.work', 'status' => $status]);

    expect($run->fresh()->status)->toBe($status)
        ->and($status->isFinished())->toBe($finished)
        ->and($run->finished())->toBe($finished)
        ->and($run->succeeded())->toBe($status === RunStatus::Succeeded)
        ->and($run->partial())->toBe($status === RunStatus::Partial)
        ->and($run->failed())->toBe($status === RunStatus::Failed)
        ->and($run->cancelled())->toBe($status === RunStatus::Cancelled)
        ->and(Run::named('rows.import')->unfinished()->count())->toBe($finished ? 0 : 1)
        ->and(Run::named('rows.import')->finished()->count())->toBe($finished ? 1 : 0)
        ->and(Run::named('rows.import')->succeeded()->count())->toBe($status === RunStatus::Succeeded ? 1 : 0);
})->with([
    [RunStatus::Queued, false], [RunStatus::Running, false], [RunStatus::Succeeded, true],
    [RunStatus::Partial, true], [RunStatus::Failed, true], [RunStatus::Cancelled, true],
]);

it('reads item outcomes, weighted counts, params and finish dates', function (ItemStatus $status, string $scope) {
    $run = Run::create(['name' => 'rows.import']);
    $item = $run->items()->create([
        'key' => 'row:9', 'status' => $status, 'code' => 'row.checked',
        'params' => ['row' => 9], 'result' => 'part:23', 'count' => 50, 'finished_at' => now(),
    ]);

    expect($item->fresh()->status)->toBe($status)
        ->and($item->fresh()->count)->toBe(50)
        ->and($item->fresh()->params)->toBe(['row' => 9])
        ->and($item->fresh()->finished_at)->toEqual($item->finished_at)
        ->and($item->run->is($run))->toBeTrue()
        ->and($run->items()->{$scope}()->pluck('key')->all())->toBe(['row:9']);

    foreach (array_diff(['pending', 'succeeded', 'failed', 'skipped'], [$scope]) as $other) {
        expect($run->items()->{$other}()->count())->toBe(0);
    }
})->with([
    [ItemStatus::Pending, 'pending'], [ItemStatus::Succeeded, 'succeeded'],
    [ItemStatus::Failed, 'failed'], [ItemStatus::Skipped, 'skipped'],
]);

it('keeps item keys unique per run and cascades items and logs on deletion', function () {
    $first = Run::create(['name' => 'rows.import']);
    $second = Run::create(['name' => 'rows.import']);
    $first->items()->create(['key' => 'row:9']);
    $second->items()->create(['key' => 'row:9']);
    $log = $first->logs()->create(['code' => 'rows.staged', 'params' => ['count' => 500]]);

    expect($log->fresh()->params)->toBe(['count' => 500])
        ->and($log->run->is($first))->toBeTrue()
        ->and($log->created_at)->not->toBeNull();
    expect(fn () => $first->items()->create(['key' => 'row:9']))->toThrow(QueryException::class);

    $first->delete();
    expect(Item::count())->toBe(1)->and(Log::count())->toBe(0);
});

it('reads persisted tree, retry and linked relations without treating linked runs as children', function () {
    $root = Run::create(['name' => 'catalog.sync']);
    $child = Run::create(['name' => 'catalog.fetch', 'parent_run_id' => $root->id, 'root_run_id' => $root->id]);
    $grandchild = Run::create(['name' => 'catalog.page', 'parent_run_id' => $child->id, 'root_run_id' => $root->id]);
    $linked = Run::create(['name' => 'archive.sync', 'linked_from_run_id' => $child->id]);
    $retry = Run::create(['name' => 'catalog.fetch', 'retry_of_run_id' => $child->id]);

    expect($child->parent->is($root))->toBeTrue()
        ->and($grandchild->root->is($root))->toBeTrue()
        ->and($root->root->is($root))->toBeTrue()
        ->and($root->parent)->toBeNull()
        ->and($child->children->modelKeys())->toBe([$grandchild->id])
        ->and($child->linked->modelKeys())->toBe([$linked->id])
        ->and($linked->linkedFrom->is($child))->toBeTrue()
        ->and($linked->root->is($linked))->toBeTrue()
        ->and($retry->retryOf->is($child))->toBeTrue();

    foreach (Run::with('root')->get() as $run) {
        expect($run->root->id)->toBe($run->root_run_id ?? $run->id);
    }

    $child->delete();
    expect($grandchild->fresh()->parent_run_id)->toBeNull()
        ->and($linked->fresh()->linked_from_run_id)->toBeNull()
        ->and($retry->fresh()->retry_of_run_id)->toBeNull();
});

it('uses an independent connection and keeps docket records after application rollback', function () {
    $application = DB::connection();
    $docket = (new Run)->getConnection();

    expect($docket->getPdo())->not->toBe($application->getPdo());
    $application->beginTransaction();
    $run = Run::create(['name' => 'rows.import']);
    $run->items()->create(['key' => 'row:9']);
    $run->logs()->create(['code' => 'rows.staged']);
    $application->rollBack();

    expect(Run::find($run->id))->not->toBeNull()
        ->and(Item::count())->toBe(1)->and(Log::count())->toBe(1);

    DB::purge($docket->getName());
    expect(Run::find($run->id))->not->toBeNull();
});

it('keeps morph targets on the application database when docket uses a different database', function () {
    config(['database.connections.records' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    config(['docket.connection' => 'records']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_13_000000_create_docket_tables.php';
    $migration->up();

    Schema::create('uploads', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    $upload = Upload::create();
    $other = Upload::create();
    $empty = Upload::create();
    $user = User::create();
    $older = $upload->runs()->create(['name' => 'rows.import']);
    $newer = $upload->runs()->create(['name' => 'rows.import']);
    $other->runs()->create(['name' => 'other.work']);
    $newer->initiator()->associate($user);
    $newer->save();

    expect($newer->getConnectionName())->toBe('records')
        ->and($newer->fresh()->subject->is($upload))->toBeTrue()
        ->and($newer->fresh()->initiator->is($user))->toBeTrue()
        ->and($upload->runs->modelKeys())->toBe([$older->id, $newer->id])
        ->and($upload->latestRun->is($newer))->toBeTrue()
        ->and($empty->latestRun)->toBeNull();

    $loaded = Run::with(['subject', 'initiator'])->findOrFail($newer->id);
    expect($loaded->subject->is($upload))->toBeTrue()
        ->and($loaded->initiator->is($user))->toBeTrue()
        ->and(Upload::with('latestRun')->findOrFail($upload->id)->latestRun->is($newer))->toBeTrue()
        ->and(Run::findOrFail($older->id)->initiator)->toBeNull();

    $migration->down();
    expect(Schema::connection('records')->hasTable('docket_runs'))->toBeFalse()
        ->and(Schema::hasTable('uploads'))->toBeTrue();
});

it('supports string-keyed subjects and morph aliases on both lazy and eager reads', function () {
    Schema::create('documents', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->timestamps();
    });

    Relation::morphMap(['document' => Document::class]);

    try {
        $document = Document::create();
        $run = $document->runs()->create(['name' => 'document.build']);

        expect($run->subject_id)->toBe($document->id)
            ->and($run->subject_type)->toBe('document')
            ->and($run->fresh()->subject->is($document))->toBeTrue()
            ->and(Run::with('subject')->findOrFail($run->id)->subject->is($document))->toBeTrue()
            ->and(Document::with('latestRun')->findOrFail($document->id)->latestRun->is($run))->toBeTrue();
    } finally {
        Relation::morphMap([], false);
    }
});

it('rejects an unknown configured database connection through Laravel', function () {
    config(['docket.connection' => 'missing']);

    expect(fn () => Run::create(['name' => 'rows.import']))
        ->toThrow(InvalidArgumentException::class, 'Database connection [missing] not configured.');
    config(['docket.connection' => null]);
});
