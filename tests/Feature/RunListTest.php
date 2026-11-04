<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeSecond;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;

beforeEach(function () {
    actingAs(new User(['id' => 1]));
    freezeSecond();
});

/** @param array<string, mixed> $attributes */
function listedRun(array $attributes = []): Run
{
    return Run::create(['name' => 'report.build', 'status' => RunStatus::Running, 'initiator_type' => User::class, 'initiator_id' => '1', ...$attributes]);
}

it('lists the viewer\'s active runs of a type with their label, current step and copy', function () {
    config(['docket.runs.labels' => ['report.build' => 'Monthly report']]);
    $run = listedRun(['steps' => ['prepare' => 'Preparing data', 'render' => 'Rendering PDF'], 'step' => 'render']);
    listedRun(['name' => 'other.type']);
    listedRun(['initiator_id' => '2']);

    $payload = getJson('/docket/runs?name=report.build')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

    expect($payload['schema_version'])->toBe(1)
        ->and($payload['runs'])->toHaveCount(1)
        ->and($payload['active'])->toBeTrue()
        ->and($payload['poll_after_ms'])->toBe(2000)
        ->and($payload['runs'][0])->toMatchArray([
            'id' => $run->id,
            'label' => 'Monthly report',
            'state' => 'running',
            'tone' => 'neutral',
            'message' => 'Working…',
            'reason' => null,
            'step' => ['key' => 'render', 'label' => 'Rendering PDF'],
            'linger_until' => null,
            'links' => [],
        ]);
});

it('marks a run slow, then timed out, with the configured copy and a help link, and never drops it', function () {
    config(['docket.runs.slow_after' => 60, 'docket.runs.timeout_after' => 300, 'docket.runs.help_url' => 'https://example.test/help']);
    listedRun();

    travel(61)->seconds();
    $slow = getJson('/docket/runs?name=report.build')->json('runs.0');
    expect($slow)->toMatchArray(['state' => 'slow', 'tone' => 'warning', 'message' => 'Taking longer than usual', 'reason' => null, 'links' => []]);

    travel(300)->seconds();
    $timedOut = getJson('/docket/runs?name=report.build')->json('runs.0');
    expect($timedOut)->toMatchArray([
        'state' => 'timed_out',
        'tone' => 'danger',
        'message' => 'Timed out',
        'reason' => 'This is taking much longer than expected. It may have stopped.',
        'links' => ['help' => ['url' => 'https://example.test/help', 'label' => 'Get help']],
    ]);

    travel(1)->days();
    expect(getJson('/docket/runs?name=report.build')->json('runs.0.state'))->toBe('timed_out');
});

it('times out at the run\'s own stale interval when no list timeout is configured', function () {
    listedRun(['stale_after' => 30]);

    travel(31)->seconds();

    expect(getJson('/docket/runs?name=report.build')->json('runs.0.state'))->toBe('timed_out');
});

it('keeps a finished run for the linger window with its output link, then drops it', function () {
    Storage::fake('local');
    Storage::disk('local')->put('reports/r.pdf', '%PDF');
    $run = listedRun();
    $run->output('reports/r.pdf', 'local', mime: 'application/pdf');
    $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

    $row = getJson('/docket/runs?name=report.build&linger=10')->json('runs.0');
    expect($row['state'])->toBe('succeeded')
        ->and($row['message'])->toBe('Done')
        ->and($row['linger_until'])->toBe(now()->addSeconds(10)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($row['links']['output']['label'])->toBe('Open')
        ->and($row['links']['output']['url'])->toStartWith('/docket/runs/'.$run->token.'/output/view?');

    get($row['links']['output']['url'])->assertOk();

    travel(11)->seconds();
    $payload = getJson('/docket/runs?name=report.build&linger=10')->json();
    expect($payload['runs'])->toBe([])
        ->and($payload['active'])->toBeFalse()
        ->and($payload['poll_after_ms'])->toBe(15000)
        ->and($payload['empty'])->toBe('Nothing running.');
});

it('keeps a failed run longer, with its failure reason', function () {
    config(['docket.runs.reasons' => [...config('docket.runs.reasons'), 'report.no_data' => 'There was no data for that month.']]);
    listedRun(['status' => RunStatus::Failed, 'finished_at' => now(), 'error' => ['code' => 'report.no_data', 'params' => []]]);
    listedRun(['status' => RunStatus::Failed, 'finished_at' => now(), 'error' => ['code' => 'run.stalled', 'params' => []]]);

    travel(60)->seconds();
    $rows = getJson('/docket/runs?name=report.build&linger=10')->json('runs');

    expect(array_column($rows, 'reason'))->toEqualCanonicalizing(['There was no data for that month.', 'It stopped responding.'])
        ->and(array_column($rows, 'message'))->toBe(['Something went wrong', 'Something went wrong']);
});

it('lists only runs the policy lets the viewer see, through the app\'s scope hook', function () {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $visible = listedRun(['initiator_id' => '9', 'subject_type' => 'workspace', 'subject_id' => '7']);
    listedRun(['initiator_id' => '9', 'subject_type' => 'workspace', 'subject_id' => '8']);
    $scoped = [];
    Docket::runs()->scopeUsing(function ($query, $viewer) use (&$scoped) {
        $scoped[] = $viewer?->getAuthIdentifier();
        $query->where('subject_id', '7');
    });

    $rows = getJson('/docket/runs?name=report.build')->json('runs');

    expect(array_column($rows, 'id'))->toBe([$visible->id])
        ->and($scoped)->toContain(1);
});

it('filters by subject and lets the app adjust a row', function () {
    $mine = listedRun(['subject_type' => 'workspace', 'subject_id' => '7']);
    listedRun(['subject_type' => 'workspace', 'subject_id' => '8']);
    Docket::runs()->describeUsing(fn (array $row, Run $run) => [...$row, 'label' => 'Report for '.$run->subject_id]);

    $rows = getJson('/docket/runs?name=report.build&subject_type=workspace&subject_id=7')->json('runs');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($mine->id)
        ->and($rows[0]['label'])->toBe('Report for 7');
});

it('lists nothing for a guest under the default policy, and is bounded by the limit', function () {
    foreach (range(1, 5) as $i) {
        listedRun();
    }

    expect(getJson('/docket/runs?name=report.build&limit=3')->json('runs'))->toHaveCount(3);
    getJson('/docket/runs?name=report.build&limit=500')->assertUnprocessable();
    getJson('/docket/runs')->assertUnprocessable();

    auth()->logout();
    expect(getJson('/docket/runs?name=report.build')->json('runs'))->toBe([]);
});

it('streams the list and pushes it again when a row changes', function () {
    Sleep::fake(syncWithCarbon: true);
    config(['docket.stream.seconds' => 3]);
    $run = listedRun();
    $ticks = 0;
    Sleep::whenFakingSleep(function () use ($run, &$ticks) {
        if (++$ticks === 1) {
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
        }
    });

    $body = get('/docket/runs/stream?name=report.build')->assertOk()->assertHeader('X-Accel-Buffering', 'no')->streamedContent();
    preg_match_all('/event: runs\ndata: (.*)\n/', $body, $matches);
    $states = array_map(fn ($json) => json_decode($json, true)['runs'][0]['state'], $matches[1]);

    expect($states)->toBe(['running', 'succeeded'])
        ->and($body)->toContain("event: end\ndata: {\"reason\":\"timeout\"}");
});

it('emits rows in the shape the JavaScript fixture uses, so the component tests render what the server sends', function () {
    config(['docket.runs.help_url' => 'https://example.test/help']);
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/runs-list.json'), true);
    listedRun(['steps' => ['prepare' => 'Preparing data', 'render' => 'Rendering PDF'], 'step' => 'render']);
    travel(2)->hours();
    $payload = getJson('/docket/runs?name=report.build')->json();

    expect(array_keys($payload))->toBe(array_keys($fixture));
    foreach ($fixture['runs'] as $row) {
        expect(array_keys($payload['runs'][0]))->toBe(array_keys($row));
    }
    expect($payload['runs'][0]['state'])->toBe($fixture['runs'][1]['state'])
        ->and($payload['runs'][0]['message'])->toBe($fixture['runs'][1]['message'])
        ->and($payload['runs'][0]['reason'])->toBe($fixture['runs'][1]['reason'])
        ->and($payload['runs'][0]['links'])->toBe($fixture['runs'][1]['links']);
});
