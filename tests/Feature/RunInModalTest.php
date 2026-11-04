<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\RecordWork;

beforeEach(function () {
    config(['queue.default' => 'sync']);
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
});

it('starts a run once and hands back the active one on a repeat, scoped by its extra values', function () {
    config(['queue.default' => 'null']);
    $first = Docket::job(new RecordWork)->name('report.preview')->uniqueBy('pdf', 2026)->dispatch();
    $again = Docket::job(new RecordWork)->name('report.preview')->uniqueBy('pdf', 2026)->dispatch();
    $other = Docket::job(new RecordWork)->name('report.preview')->uniqueBy('csv', 2026)->dispatch();
    $plain = Docket::job(new RecordWork)->name('report.preview')->unique()->dispatch();

    expect($again->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id)
        // Plain unique() reuses any active run of the name and subject, scoped or not, as it always has.
        ->and($plain->id)->toBe($first->id)
        ->and(Run::count())->toBe(2);

    Run::query()->whereKey($first->id)->update(['status' => RunStatus::Succeeded, 'finished_at' => now(), 'unique_key' => null]);
    expect(Docket::job(new RecordWork)->name('report.preview')->uniqueBy('pdf', 2026)->dispatch()->id)->not->toBe($first->id);
});

it('never creates a second run when another request starts the same one at the same moment', function () {
    config(['queue.default' => 'null']);
    $database = config('database.connections.testing');
    config(['database.connections.racer' => $database]);
    $competitor = null;
    // The other request inserts the same active run on its own connection after this one has looked and found nothing.
    Run::creating(function (Run $run) use (&$competitor) {
        if ($competitor === null && $run->getAttribute('unique_key') !== null) {
            $competitor = (string) Str::ulid();
            DB::connection('racer')->table('docket_runs')->insert([
                'id' => $competitor, 'token' => bin2hex(random_bytes(32)), 'name' => $run->name, 'status' => 'queued',
                'unique_key' => $run->getAttribute('unique_key'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $ticket = Docket::job(new RecordWork)->name('report.preview')->uniqueBy('pdf')->dispatch();

    expect($ticket->id)->toBe($competitor)
        ->and(Run::query()->where('name', 'report.preview')->count())->toBe(1);
});

it('gives a modal prop with the token, poll and stream links and the full first snapshot', function () {
    config(['queue.default' => 'null', 'docket.runs.reasons' => ['report.render_failed' => 'The renderer timed out.']]);
    $ticket = Docket::job(new RecordWork)->name('report.preview')->unique()->dispatch();
    $run = $ticket->run();
    $run->steps(['prepare' => 'Preparing', 'render' => 'Rendering']);

    $prop = $ticket->snapshot();
    expect($prop)->toBe(Docket::snapshot($run->refresh()))
        ->and($prop['token'])->toBe($run->token)
        ->and($prop['links']['poll'])->toBe(url('docket/runs/'.$run->token))
        ->and($prop['links']['stream'])->toBe(url('docket/runs/'.$run->token.'/stream'))
        ->and($prop)->toHaveKeys(['progress', 'steps', 'checklist', 'failure', 'settling'])
        ->and(array_column($prop['checklist'], 'state'))->toBe(['pending', 'pending'])
        ->and($prop['failure'])->toBeNull();

    $run->update(['step' => 'render', 'status' => RunStatus::Failed, 'finished_at' => now(), 'error' => ['code' => 'report.render_failed', 'params' => []]]);
    expect($ticket->snapshot()['failure'])->toBe(['step' => 'Rendering', 'code' => 'report.render_failed', 'reason' => 'The renderer timed out.']);
});

it('gives each run its own output path', function () {
    $first = Run::create(['name' => 'report']);
    $second = Run::create(['name' => 'report']);

    expect($first->outputPath('Report.pdf', 'reports/'))->toBe('reports/'.$first->id.'/Report.pdf')
        ->and($second->outputPath('Report.pdf', 'reports'))->not->toBe($first->outputPath('Report.pdf', 'reports'))
        ->and($first->outputPath('../x/y.pdf'))->toBe('docket/'.$first->id.'/..-x-y.pdf');
});
