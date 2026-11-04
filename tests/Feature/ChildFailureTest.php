<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Policies\RunPolicy;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function () {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
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

it('finishes the parent partial when an optional queued child fails, listing the child with its reason', function () {
    config(['docket.runs.reasons' => ['mail.unreadable' => 'The message could not be read.']]);
    $root = Docket::run('intake', function () {
        Docket::job(fn () => Docket::current()?->fail('mail.unreadable'))->name('reading')->dispatch();
    })->run();

    artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
    $root->refresh();
    $child = Run::named('reading')->sole();
    $snapshot = $root->toArray();

    expect($root->status)->toBe(RunStatus::Partial)
        ->and($root->error)->toBeNull()
        ->and($child->required)->toBeFalse()
        ->and($snapshot['failed_children'])->toBe([['id' => $child->id, 'name' => 'reading', 'label' => 'reading', 'required' => false, 'code' => 'mail.unreadable', 'reason' => 'The message could not be read.']])
        ->and(end($snapshot['checklist']))->toBe(['key' => 'child:'.$child->id, 'label' => 'reading', 'state' => 'skipped', 'detail' => null, 'reason' => 'The message could not be read.', 'code' => 'mail.unreadable', 'required' => false, 'action' => null]);
});

it('fails the parent with the child\'s code and a trail back to it when a required queued child fails', function () {
    config(['docket.runs.reasons' => ['mail.unreadable' => 'The message could not be read.']]);
    $root = Docket::run('intake', function () {
        Docket::job(fn () => Docket::current()?->fail('mail.unreadable', ['attempt' => 2]))->name('reading')->required()->dispatch();
    })->run();

    artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
    $root->refresh();
    $child = Run::named('reading')->sole();

    expect($root->status)->toBe(RunStatus::Failed)
        ->and($root->error)->toBe(['code' => 'mail.unreadable', 'params' => ['attempt' => 2, 'child' => $child->id]])
        ->and($root->toArray()['error']['code'])->toBe('mail.unreadable')
        ->and($root->toArray()['failed_children'][0]['required'])->toBeTrue()
        ->and(array_column($root->toArray()['checklist'], 'state'))->not->toContain('skipped');
});

it('rolls a required failure up through every required level, and stops at an optional one', function (bool $middleRequired) {
    $execute = fn () => Docket::run('root', function () use ($middleRequired) {
        $middle = Docket::name('middle')->required($middleRequired);
        try {
            $middle->run(function () {
                try {
                    Docket::required()->run('leaf', fn () => Docket::current()?->fail('render.failed'));
                } catch (RunFailed) {
                    // The middle run's own code carries on; the required leaf has already failed it.
                }

                return null;
            });
        } catch (RunFailed) {
        }
    });

    $execute();
    $root = Run::named('root')->sole();
    $middle = Run::named('middle')->sole();
    $leafRun = Run::named('leaf')->sole();

    expect($middle->status)->toBe(RunStatus::Failed)
        ->and($middle->error)->toBe(['code' => 'render.failed', 'params' => ['child' => $leafRun->id]]);

    if ($middleRequired) {
        expect($root->status)->toBe(RunStatus::Failed)
            ->and($root->error)->toBe(['code' => 'render.failed', 'params' => ['child' => $middle->id]])
            ->and($root->failureCode())->toBe('render.failed');
    } else {
        expect($root->status)->toBe(RunStatus::Partial)
            ->and($root->error)->toBeNull()
            ->and($root->toArray()['failed_children'][0]['code'])->toBe('render.failed');
    }
})->with(['required middle' => true, 'optional middle' => false]);

it('lists failed children for the list\'s viewer, not the signed-in user', function (bool $signedIn) {
    Gate::policy(Run::class, RunPolicy::class);
    $viewer = new User(['id' => 1]);
    $root = Run::create(['name' => 'intake', 'status' => RunStatus::Partial, 'finished_at' => now(), 'children_failed' => 1, 'initiator_type' => User::class, 'initiator_id' => '1']);
    $visible = Run::create(['name' => 'reading', 'status' => RunStatus::Failed, 'finished_at' => now(), 'parent_run_id' => $root->id, 'root_run_id' => $root->id, 'initiator_type' => User::class, 'initiator_id' => '1']);
    Run::create(['name' => 'attaching', 'status' => RunStatus::Failed, 'finished_at' => now(), 'parent_run_id' => $root->id, 'root_run_id' => $root->id, 'initiator_type' => User::class, 'initiator_id' => '2']);
    if ($signedIn) {
        actingAs(new User(['id' => 2]));
    }

    $row = Docket::runs()->rows(['name' => 'intake'], $viewer)[0];

    expect(array_column($row['failed_children'], 'id'))->toBe([$visible->id])
        ->and(array_column($root->failedChildren($viewer), 'id'))->toBe([$visible->id]);
})->with(['another user signed in' => true, 'nobody signed in' => false]);
