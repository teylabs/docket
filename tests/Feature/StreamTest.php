<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    actingAs(new User(['id' => 1]));
    Sleep::fake(syncWithCarbon: true);
});

/** @return list<array{event: string|null, id: string|null, data: string|null, comment: bool, retry: string|null}> */
function frames(string $body): array
{
    $frames = [];
    foreach (array_filter(explode("\n\n", $body)) as $block) {
        $frame = ['event' => null, 'id' => null, 'data' => null, 'comment' => false, 'retry' => null];
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, ':')) {
                $frame['comment'] = true;
            } elseif (str_contains($line, ': ')) {
                [$field, $value] = explode(': ', $line, 2);
                $frame[$field] = $value;
            }
        }
        $frames[] = $frame;
    }

    return $frames;
}

it('streams the snapshot once and ends when the run has already finished', function () {
    $run = Run::create(['name' => 'export', 'status' => RunStatus::Succeeded, 'finished_at' => now(), 'initiator_type' => User::class, 'initiator_id' => '1']);

    $response = get('/docket/runs/'.$run->token.'/stream')->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
        ->assertHeader('X-Accel-Buffering', 'no')
        ->assertHeader('Cache-Control', 'no-store, private');
    $frames = frames($response->streamedContent());

    expect($frames[0]['retry'])->toBe('2000')
        ->and($frames[1]['event'])->toBe('snapshot')
        ->and($frames[1]['id'])->toBe((string) $run->revision)
        ->and(json_decode($frames[1]['data'], true))->toBe(json_decode($run->refresh()->toJson(), true))
        ->and($frames[2]['event'])->toBe('end')
        ->and(json_decode($frames[2]['data'], true))->toBe(['reason' => 'finished'])
        ->and($frames)->toHaveCount(3);
});

it('pushes each new revision, sends heartbeats and stops when the run finishes', function () {
    config(['docket.stream.heartbeat_seconds' => 2]);
    $run = Run::create(['name' => 'export', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1']);
    $ticks = 0;
    Sleep::whenFakingSleep(function () use ($run, &$ticks) {
        $ticks++;
        if ($ticks === 1) {
            $run->update(['step' => 'render']);
        } elseif ($ticks === 4) {
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
        }
    });

    $frames = frames(get('/docket/runs/'.$run->token.'/stream')->assertOk()->streamedContent());
    $snapshots = array_values(array_filter($frames, fn ($frame) => $frame['event'] === 'snapshot'));

    expect(array_map(fn ($frame) => json_decode($frame['data'], true)['revision'], $snapshots))->toBe([1, 2, 3])
        ->and(json_decode($snapshots[1]['data'], true)['step'])->toBe('render')
        ->and(json_decode($snapshots[2]['data'], true)['status'])->toBe('succeeded')
        ->and(array_filter($frames, fn ($frame) => $frame['comment']))->not->toBeEmpty()
        ->and(end($frames)['event'])->toBe('end')
        ->and(json_decode(end($frames)['data'], true))->toBe(['reason' => 'finished']);
});

it('closes an unfinished run after the configured time so the client reconnects', function () {
    config(['docket.stream.seconds' => 5]);
    $run = Run::create(['name' => 'export', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1']);

    $frames = frames(get('/docket/runs/'.$run->token.'/stream')->assertOk()->streamedContent());

    expect(array_values(array_filter($frames, fn ($frame) => $frame['event'] === 'snapshot')))->toHaveCount(1)
        ->and(json_decode(end($frames)['data'], true))->toBe(['reason' => 'timeout']);
});

it('ends the stream when access is revoked mid-stream', function () {
    $run = Run::create(['name' => 'export', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1']);
    Sleep::whenFakingSleep(fn () => Gate::before(fn () => false));

    $frames = frames(get('/docket/runs/'.$run->token.'/stream')->assertOk()->streamedContent());

    expect(end($frames)['event'])->toBe('end')
        ->and(json_decode(end($frames)['data'], true))->toBe(['reason' => 'unavailable']);
});
