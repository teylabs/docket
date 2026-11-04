<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Listing\RunList;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\travel;

beforeEach(function () {
    actingAs(new User(['id' => 1]));
    freezeSecond();
});

it('keeps the last step active and the run unsettled until the completion listeners have recorded the output', function () {
    Storage::fake('local');
    Storage::disk('local')->put('r.pdf', '%PDF');
    $run = Run::create(['name' => 'report.build', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1', 'steps' => ['prepare' => 'Preparing', 'render' => 'Rendering'], 'step' => 'render']);
    $during = null;
    Event::listen(RunFinished::class, function (RunFinished $event) use (&$during) {
        $during = Run::query()->findOrFail($event->run->id)->toArray();
        Run::query()->findOrFail($event->run->id)->output('r.pdf', 'local', mime: 'application/pdf');
    });

    app(Lifecycle::class)->finish($run->id, RunStatus::Succeeded);

    expect($during['status'])->toBe('succeeded')
        ->and($during['settling'])->toBeTrue()
        ->and($during['output'])->toBeNull()
        ->and(array_column($during['steps'], 'status'))->toBe(['done', 'active'])
        ->and($during['poll_after_ms'])->toBe(2000);

    $after = $run->refresh()->toArray();
    expect($after['settling'])->toBeFalse()
        ->and($after['output'])->not->toBeNull()
        ->and($after['links'])->toHaveKey('download')
        ->and(array_column($after['steps'], 'status'))->toBe(['done', 'done'])
        ->and($after['poll_after_ms'])->toBeNull();
});

it('lists a settling run as finishing, without an output link, and never holds it open past settle_seconds', function () {
    $run = Run::create(['name' => 'report.build', 'status' => RunStatus::Succeeded, 'finished_at' => now(), 'settling' => true, 'initiator_type' => User::class, 'initiator_id' => '1']);

    $row = app(RunList::class)->row($run);
    expect($row)->toMatchArray(['state' => 'finishing', 'active' => true, 'message' => 'Finishing…', 'links' => [], 'linger_until' => null]);

    travel(31)->seconds();
    expect($run->refresh()->settling())->toBeFalse()
        ->and(app(RunList::class)->row($run)['state'])->toBe('succeeded');
});

it('leaves runs from before the migration settled', function () {
    $run = Run::create(['name' => 'old', 'status' => RunStatus::Succeeded, 'finished_at' => now()]);

    expect($run->refresh()->settling())->toBeFalse()
        ->and($run->toArray()['settling'])->toBeFalse();
});

it('sends the steps as checklist items, with the failure reason as text and the last step active while settling', function () {
    config(['docket.runs.reasons' => ['report.render_failed' => 'The renderer timed out.']]);
    $run = Run::create(['name' => 'r', 'status' => RunStatus::Failed, 'finished_at' => now(), 'steps' => ['prepare' => 'Preparing', 'render' => 'Rendering'], 'step' => 'render', 'error' => ['code' => 'report.render_failed', 'params' => []]]);

    expect($run->toArray()['checklist'])->toBe([
        ['key' => 'prepare', 'label' => 'Preparing', 'state' => 'done', 'detail' => null, 'reason' => null, 'required' => true, 'action' => null],
        ['key' => 'render', 'label' => 'Rendering', 'state' => 'failed', 'detail' => null, 'reason' => 'The renderer timed out.', 'required' => true, 'action' => null],
    ]);

    $run->update(['status' => RunStatus::Succeeded, 'error' => null, 'settling' => true]);
    expect(array_column($run->refresh()->checklist(), 'state'))->toBe(['done', 'active']);
});
