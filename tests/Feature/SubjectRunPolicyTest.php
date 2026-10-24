<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Policies\RunPolicy;
use Tey\Docket\Policies\SubjectRunPolicy;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\Upload;
use Tey\Docket\Tests\Fixtures\User;
use Tey\Docket\Tests\Fixtures\Workspace;
use Tey\Docket\Tests\Fixtures\WorkspacePolicy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\postJson;

beforeEach(function () {
    foreach (['users', 'workspaces', 'uploads'] as $name) {
        Schema::create($name, function (Blueprint $table) use ($name) {
            $table->id();
            if ($name === 'uploads') {
                $table->unsignedBigInteger('workspace_id')->nullable();
            }
            $table->timestamps();
        });
    }
    Schema::create('user_workspace', function (Blueprint $table) {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('workspace_id');
        $table->boolean('can_manage')->default(false);
    });

    $workspace = Workspace::create();
    $otherWorkspace = Workspace::create();
    $manager = User::create();
    $reader = User::create();
    $outsider = User::create();
    $workspace->members()->attach($manager, ['can_manage' => true]);
    $workspace->members()->attach($reader);
    $otherWorkspace->members()->attach($outsider, ['can_manage' => true]);
    Gate::policy(Workspace::class, WorkspacePolicy::class);
    Queue::fake();
});

it('keeps initiator-only access until the subject policy is registered', function () {
    $workspace = Workspace::findOrFail(1);
    $reader = User::findOrFail(2);
    actingAs(User::findOrFail(1));
    $scheduled = Docket::about($workspace)->by(null)->run('scheduled', static fn () => null)->run();
    $owned = Docket::about($workspace)->run('owned', static fn () => null)->run();

    expect(Gate::getPolicyFor(Run::class))->toBeInstanceOf(RunPolicy::class);
    foreach (['view', 'cancel', 'retry'] as $ability) {
        expect(Gate::allows($ability, $scheduled))->toBeFalse()
            ->and(Gate::allows($ability, $owned))->toBeTrue()
            ->and(Gate::forUser($reader)->allows($ability, $owned))->toBeFalse();
    }
    getJson('/docket/runs/'.$scheduled->token)->assertNotFound();
});

it('lets tenant managers read and cancel initiator-less work and retry it with the same subject', function () {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $workspace = Workspace::findOrFail(1);
    $run = Docket::job(static fn () => null)->about($workspace)->by(null)->dispatch()->run();
    $run->logs()->create(['code' => 'work.queued']);
    $run->items()->create(['key' => 'row:1']);
    actingAs(User::findOrFail(1));

    getJson('/docket/runs/'.$run->token)->assertOk()->assertJsonPath('links.cancel', url('/docket/runs/'.$run->token.'/cancel'));
    getJson('/docket/runs/'.$run->token.'/items')->assertOk()->assertJsonPath('data.0.key', 'row:1');
    getJson('/docket/runs/'.$run->token.'/logs')->assertOk()->assertJsonPath('data.0.code', 'work.queued');
    postJson('/docket/runs/'.$run->token.'/cancel')->assertOk()->assertJsonPath('cancel_requested', true);

    $run->update(['status' => RunStatus::Cancelled, 'finished_at' => now()]);
    $response = postJson('/docket/runs/'.$run->token.'/retry')->assertAccepted();
    $retry = Run::findOrFail($response->json('id'));
    expect($retry->retry_of_run_id)->toBe($run->id)
        ->and($retry->initiator)->toBeNull()
        ->and($retry->subject->is($workspace))->toBeTrue();
    getJson('/docket/runs/'.$retry->token)->assertOk();
});

it('separates tenant read permission from control permission', function () {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $run = Docket::job(static fn () => null)->about(Workspace::findOrFail(1))->by(null)->dispatch()->run();
    actingAs(User::findOrFail(2));

    getJson('/docket/runs/'.$run->token)->assertOk()->assertJsonMissingPath('links.cancel');
    postJson('/docket/runs/'.$run->token.'/cancel')->assertForbidden();
    $run->update(['status' => RunStatus::Failed, 'finished_at' => now()]);
    expect($run->ticket()->toArray()['links'])->not->toHaveKey('retry');
    postJson('/docket/runs/'.$run->token.'/retry')->assertForbidden();
    expect(Run::count())->toBe(1)->and($run->refresh()->cancel_requested)->toBeFalse();
});

it('hides other tenants on every route even from the initiator', function (string $method, string $suffix) {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    actingAs(User::findOrFail(3));
    $run = Docket::job(static fn () => null)->about(Workspace::findOrFail(1))->dispatch()->run();

    json($method, '/docket/runs/'.$run->token.$suffix)->assertNotFound()
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($run->refresh()->cancel_requested)->toBeFalse()->and(Run::count())->toBe(1);
})->with([
    ['GET', ''], ['GET', '/items'], ['GET', '/logs'], ['GET', '/children'],
    ['POST', '/cancel'], ['POST', '/retry'],
]);

it('denies guests even when the subject permits guests', function () {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    Gate::before(fn (?User $user, string $ability, array $arguments): ?bool => ($arguments[0] ?? null) instanceof Workspace ? true : null);
    $workspace = Workspace::findOrFail(1);
    $run = Docket::about($workspace)->by(null)->run('scheduled', static fn () => null)->run();

    expect(Gate::forUser(null)->allows('view', $workspace))->toBeTrue();
    getJson('/docket/runs/'.$run->token)->assertNotFound();
    foreach (['view', 'cancel', 'retry'] as $ability) {
        expect(Gate::forUser(null)->allows($ability, $run))->toBeFalse();
    }
});

it('denies absent deleted and unrecognized subjects without falling back to the initiator', function (string $subject) {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $workspace = Workspace::findOrFail(1);
    actingAs(User::findOrFail(1));
    $pending = Docket::job(static fn () => null);
    if ($subject !== 'absent') {
        $pending->about($subject === 'unrecognized' ? Upload::create() : $workspace);
    }
    $run = $pending->dispatch()->run();
    if ($subject === 'deleted') {
        $workspace->delete();
    }

    foreach (['view', 'cancel', 'retry'] as $ability) {
        expect(Gate::allows($ability, $run))->toBeFalse();
    }
    getJson('/docket/runs/'.$run->token)->assertNotFound();
})->with(['absent', 'deleted', 'unrecognized']);

it('resolves a tenant from the stored subject through a container-bound policy', function () {
    app()->bind(SubjectRunPolicy::class, fn () => new SubjectRunPolicy(
        fn (Model $subject): ?Model => Workspace::find($subject->getAttribute('workspace_id')),
    ));
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $manager = User::findOrFail(1);
    $reader = User::findOrFail(2);
    $outsider = User::findOrFail(3);
    $upload = Upload::create(['workspace_id' => 1]);
    $run = Docket::job(static fn () => null)->about($upload)->by(null)->dispatch()->run();

    foreach (['view', 'cancel', 'retry'] as $ability) {
        expect(Gate::forUser($manager)->allows($ability, $run))->toBeTrue()
            ->and(Gate::forUser($outsider)->allows($ability, $run))->toBeFalse();
    }
    expect(Gate::forUser($reader)->allows('view', $run))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('retry', $run))->toBeFalse();

    $upload->update(['workspace_id' => null]);
    expect(Gate::forUser($manager)->allows('view', $run))->toBeFalse();
});

it('rechecks membership and subject changes for reused tickets and before poll throttling', function () {
    app()->bind(SubjectRunPolicy::class, fn () => new SubjectRunPolicy(
        fn (Model $subject): ?Model => Workspace::find($subject->getAttribute('workspace_id')),
    ));
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $workspace = Workspace::findOrFail(1);
    $manager = User::findOrFail(1);
    actingAs($manager);
    $upload = Upload::create(['workspace_id' => 1]);
    $run = Docket::job(static fn () => null)->about($upload)->by(null)->dispatch()->run();
    $ticket = $run->ticket();
    expect($ticket->toArray()['links'])->toHaveKey('cancel');
    getJson('/docket/runs/'.$run->token)->assertOk();
    $workspace->members()->detach($manager);
    expect($ticket->toArray()['links'])->not->toHaveKey('cancel');
    getJson('/docket/runs/'.$run->token)->assertNotFound();
    postJson('/docket/runs/'.$run->token.'/cancel')->assertNotFound();

    $workspace->members()->attach($manager, ['can_manage' => true]);
    expect($ticket->toArray()['links'])->toHaveKey('cancel');
    $upload->update(['workspace_id' => 2]);
    expect($ticket->toArray()['links'])->not->toHaveKey('cancel');
    $upload->update(['workspace_id' => 1]);
    expect($ticket->toArray()['links'])->toHaveKey('cancel');
    $upload->delete();
    expect($ticket->toArray()['links'])->not->toHaveKey('cancel');
});

it('filters each child and tree branch by its own tenant and honours the explicit viewer', function () {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    $workspace = Workspace::findOrFail(1);
    $otherWorkspace = Workspace::findOrFail(2);
    $root = Docket::about($workspace)->by(null)->run('root', function () use ($workspace, $otherWorkspace) {
        Docket::about($workspace)->by(null)->run('visible', static fn () => null);
        Docket::about($otherWorkspace)->by(null)->run('hidden', function () use ($workspace) {
            Docket::about($workspace)->by(null)->run('hidden.branch', static fn () => null);
        });
    })->run();
    $manager = User::findOrFail(1);
    actingAs($manager);
    getJson('/docket/runs/'.$root->token.'/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'visible');
    getJson('/docket/runs/'.$root->token)->assertOk()->assertJsonPath('children.total', 2);
    actingAs(User::findOrFail(3));
    expect($root->tree())->toBe([])
        ->and(array_column($root->tree($manager)['children'], 'name'))->toBe(['visible']);
});

it('supports morph aliases and leaves subject attributes out of JSON and serialized tickets', function () {
    Gate::policy(Run::class, SubjectRunPolicy::class);
    Relation::morphMap(['workspace' => Workspace::class]);
    try {
        actingAs(User::findOrFail(1));
        $run = Docket::job(static fn () => null)->about(Workspace::findOrFail(1))->by(null)->dispatch()->run();
        expect($run->subject_type)->toBe('workspace');
        getJson('/docket/runs/'.$run->token)->assertOk()->assertJsonMissingPath('subject')->assertJsonMissingPath('workspace_id');
        $ticket = $run->ticket();
        expect(array_keys($ticket->toArray()))->toBe(['schema_version', 'id', 'token', 'name', 'status', 'revision', 'poll_after_ms', 'links'])
            ->and(serialize($ticket))->not->toContain('subject', 'workspace', 'can_manage')
            ->and(unserialize(serialize($ticket))->toArray())->toBe($ticket->toArray());
    } finally {
        Relation::morphMap([], false);
    }
});
