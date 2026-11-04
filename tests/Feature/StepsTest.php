<?php

use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    actingAs(new User(['id' => 1]));
});

function stepRun(): Run
{
    return Run::create(['name' => 'report.build', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1']);
}

/** @return list<string> */
function statuses(Run $run): array
{
    return array_column($run->fresh()->toArray()['steps'], 'status');
}

it('lists the declared steps up front, all pending, with keys and labels in order', function () {
    $run = stepRun()->steps(['prepare' => 'Preparing data', 'render' => 'Rendering PDF', 'save' => 'Saving']);

    expect($run->fresh()->toArray()['steps'])->toBe([
        ['key' => 'prepare', 'label' => 'Preparing data', 'status' => 'pending'],
        ['key' => 'render', 'label' => 'Rendering PDF', 'status' => 'pending'],
        ['key' => 'save', 'label' => 'Saving', 'status' => 'pending'],
    ]);
});

it('marks the steps before the current one done as the work advances, and all done on success', function () {
    $run = stepRun()->steps(['prepare' => 'Preparing data', 'render' => 'Rendering PDF', 'save' => 'Saving']);

    $run->step('prepare');
    expect(statuses($run))->toBe(['active', 'pending', 'pending']);
    $run->step('save');
    expect(statuses($run))->toBe(['done', 'done', 'active']);

    $run->forceFill(['status' => RunStatus::Succeeded, 'finished_at' => now()])->save();
    expect(statuses($run))->toBe(['done', 'done', 'done']);
});

it('marks the active step failed with the error and leaves later steps pending', function () {
    auth()->forgetGuards();
    expect(fn () => Docket::run('report.build', function (Run $run) {
        $run->steps(['prepare' => 'Preparing data', 'render' => 'Rendering PDF', 'save' => 'Saving'])->step('render');
        $run->fail('report.render_failed');
    }))->toThrow(RunFailed::class);
    $run = Run::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->toArray()['steps'])->toBe([
            ['key' => 'prepare', 'label' => 'Preparing data', 'status' => 'done'],
            ['key' => 'render', 'label' => 'Rendering PDF', 'status' => 'failed', 'error' => 'report.render_failed'],
            ['key' => 'save', 'label' => 'Saving', 'status' => 'pending'],
        ]);
});

it('keeps the snapshot\'s other fields and serves the same steps to the poll and stream endpoints', function () {
    $run = stepRun()->steps(['prepare' => 'Preparing data', 'render' => 'Rendering PDF'])->step('render');
    $polled = getJson('/docket/runs/'.$run->token)->assertOk()->json();

    expect($polled['step'])->toBe('render')
        ->and($polled)->toHaveKeys(['progress', 'counts', 'error', 'output', 'links'])
        ->and(array_column($polled['steps'], 'status'))->toBe(['done', 'active']);

    $run->forceFill(['status' => RunStatus::Succeeded, 'finished_at' => now()])->save();
    preg_match('/event: snapshot\ndata: (.+)\n/', get('/docket/runs/'.$run->token.'/stream')->streamedContent(), $frame);
    expect(json_decode($frame[1], true)['steps'])->toBe(json_decode(json_encode($run->fresh()->toArray()['steps']), true));
});

it('has no steps when the work declares none, and refuses empty or malformed declarations', function () {
    expect(stepRun()->toArray()['steps'])->toBe([]);
    expect(fn () => stepRun()->steps([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => stepRun()->steps(['prepare' => '']))->toThrow(InvalidArgumentException::class);
});
