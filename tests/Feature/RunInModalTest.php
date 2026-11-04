<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Policies\RunPolicy;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\GuestRunPolicy;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\RecordWork;
use Tey\Docket\Tests\Fixtures\User;
use Tey\Docket\Tests\Fixtures\Workspace;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    foreach (['users', 'workspaces'] as $table) {
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->timestamps();
        });
    }
    config(['queue.default' => 'sync']);
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
});

it('starts a run once and hands back the active one on a repeat, scoped by its extra values', function () {
    actingAs(new User(['id' => 1]));
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
    actingAs(new User(['id' => 1]));
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
        ->and($first->outputPath('../x/y.pdf'))->toBe('docket/'.$first->id.'/-x-y.pdf');
});

it('never resolves an output path outside the run\'s own folder', function (string $name, string $expected) {
    $run = Run::create(['name' => 'report']);

    expect($run->outputPath($name, 'reports'))->toBe('reports/'.$run->id.'/'.$expected);
})->with([
    'dot' => ['.', 'output'],
    'dot dot' => ['..', 'output'],
    'dots and spaces' => [' ... ', 'output'],
    'hidden file' => ['.env', 'env'],
    'empty' => ['', 'output'],
]);

it('keeps start-or-reuse per user by default, on one subject and with no subject', function (?int $subjectId) {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, RunPolicy::class);
    $subject = $subjectId === null ? null : new Workspace(['id' => $subjectId]);
    $start = function (int $user) use ($subject) {
        actingAs(new User(['id' => $user]));
        $pending = Docket::job(new RecordWork)->name('report.preview')->uniqueBy('pdf');

        return ($subject === null ? $pending : $pending->about($subject))->dispatch();
    };

    $alice = $start(1);
    $bob = $start(2);
    $aliceAgain = $start(1);

    expect($bob->id)->not->toBe($alice->id)
        ->and($aliceAgain->id)->toBe($alice->id)
        ->and($bob->run()->initiator_id)->toBe('2');

    // Plain unique() too, including a run left from before the upgrade under the old name-and-subject key.
    actingAs(new User(['id' => 1]));
    $plain = Docket::job(new RecordWork)->name('report.legacy')->unique()->dispatch();
    actingAs(new User(['id' => 2]));
    expect(Docket::job(new RecordWork)->name('report.legacy')->unique()->dispatch()->id)->not->toBe($plain->id);
})->with(['one subject' => 7, 'no subject' => null]);

it('shares a run only when asked, and only with viewers its policy admits', function () {
    config(['queue.default' => 'null']);
    $subject = new Workspace(['id' => 7]);
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    actingAs(new User(['id' => 1]));
    $alice = Docket::job(new RecordWork)->name('report.preview')->about($subject)->shared()->uniqueBy('pdf')->dispatch();
    actingAs(new User(['id' => 2]));
    $bob = Docket::job(new RecordWork)->name('report.preview')->about($subject)->shared()->uniqueBy('pdf')->dispatch();

    expect($bob->id)->toBe($alice->id);

    Gate::policy(Run::class, RunPolicy::class);
    expect(fn () => Docket::job(new RecordWork)->name('report.preview')->about($subject)->shared()->uniqueBy('pdf')->dispatch())
        ->toThrow(AuthorizationException::class);
});

it('refuses a snapshot prop for a run the signed-in user may not view', function () {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, RunPolicy::class);
    actingAs(new User(['id' => 1]));
    $ticket = Docket::job(new RecordWork)->name('report.preview')->unique()->dispatch();
    expect($ticket->snapshot()['id'])->toBe($ticket->id);

    actingAs(new User(['id' => 2]));
    expect(fn () => $ticket->snapshot())->toThrow(AuthorizationException::class)
        ->and(fn () => Docket::snapshot($ticket->run()))->toThrow(AuthorizationException::class);
});

it('keeps an idempotency key per user unless the run is shared, and honours a key stored before the upgrade for its own user', function () {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    $start = function (int $user, bool $shared = false) {
        actingAs(new User(['id' => $user]));
        $pending = Docket::job(new RecordWork)->name($shared ? 'import.shared' : 'import')->idempotencyKey('request:1');

        return ($shared ? $pending->shared() : $pending)->dispatch();
    };

    $alice = $start(1);
    expect($start(1)->id)->toBe($alice->id)
        ->and($start(2)->id)->not->toBe($alice->id);

    $shared = $start(1, true);
    expect($start(2, true)->id)->toBe($shared->id)
        ->and($shared->id)->not->toBe($alice->id);

    $legacy = Run::create(['name' => 'export', 'idempotency_key' => hash('sha256', serialize(['export', 'request:9'])), 'initiator_type' => User::class, 'initiator_id' => '1']);
    actingAs(new User(['id' => 1]));
    expect(Docket::job(new RecordWork)->name('export')->idempotencyKey('request:9')->dispatch()->id)->toBe($legacy->id);
    actingAs(new User(['id' => 2]));
    expect(Docket::job(new RecordWork)->name('export')->idempotencyKey('request:9')->dispatch()->id)->not->toBe($legacy->id);
});

it('never reuses a run for unattributed callers unless it is shared', function () {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, RunPolicy::class);

    // Two guests (or a command and a webhook) with no subject: separate runs, and no exception.
    $first = Docket::job(new RecordWork)->name('nightly.sync')->unique()->dispatch();
    $second = Docket::job(new RecordWork)->name('nightly.sync')->unique()->dispatch();
    $keyed = Docket::job(new RecordWork)->name('nightly.sync')->idempotencyKey('hook:1')->dispatch();
    $keyedAgain = Docket::job(new RecordWork)->name('nightly.sync')->idempotencyKey('hook:1')->dispatch();

    expect($second->id)->not->toBe($first->id)
        ->and($keyedAgain->id)->not->toBe($keyed->id)
        ->and(Run::query()->whereNotNull('unique_key')->orWhereNotNull('idempotency_key')->count())->toBe(0);

    // A system dispatch that needs de-duplication says so: shared, with a subject and a scope.
    $subject = new Workspace(['id' => 7]);
    $shared = Docket::job(new RecordWork)->name('nightly.sync')->about($subject)->shared()->uniqueBy('2026-11')->dispatch();
    $sharedAgain = Docket::job(new RecordWork)->name('nightly.sync')->about($subject)->shared()->uniqueBy('2026-11')->dispatch();
    $notShared = Docket::job(new RecordWork)->name('nightly.sync')->about($subject)->uniqueBy('2026-11')->dispatch();

    expect($sharedAgain->id)->toBe($shared->id)
        ->and($notShared->id)->not->toBe($shared->id);
});

/** Runs the callback as if serving an HTTP request rather than a console or queue process. */
function asHttpRequest(Closure $callback): mixed
{
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $previous = $property->getValue(app());
    $property->setValue(app(), false);

    try {
        return $callback();
    } finally {
        $property->setValue(app(), $previous);
    }
}

it('checks a guest against the policy before reusing a shared run during an HTTP request', function (string $policy, bool $system, bool $allowed) {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, $policy);
    $subject = new Workspace(['id' => 7]);
    $start = fn () => Docket::job(new RecordWork)->name('public.report')->about($subject)->shared(system: $system)->uniqueBy('pdf')->dispatch();

    $first = $start(); // the first start creates the run; only reuse is checked
    $again = fn () => asHttpRequest($start);

    if ($allowed) {
        expect($again()->id)->toBe($first->id);
    } else {
        expect($again)->toThrow(AuthorizationException::class);
    }
})->with([
    'guest, initiator-only policy: refused' => [RunPolicy::class, false, false],
    'guest, policy admitting guests: allowed' => [GuestRunPolicy::class, false, true],
    'webhook declaring system work: allowed' => [RunPolicy::class, true, true],
]);

it('lets a console or queue process reuse a shared run without a viewer to check', function () {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, RunPolicy::class);
    $subject = new Workspace(['id' => 7]);
    $start = fn () => Docket::job(new RecordWork)->name('nightly.report')->about($subject)->shared()->uniqueBy('2026-11')->dispatch();

    expect(app()->runningInConsole())->toBeTrue()
        ->and($start()->id)->toBe($start()->id);
});

it('refuses a guest a shared run through a real HTTP request when the policy needs a user', function () {
    config(['queue.default' => 'null']);
    Gate::policy(Run::class, RunPolicy::class);
    Route::get('/docket-test/shared-report', function () {
        $run = Docket::job(new RecordWork)->name('public.report')->about(new Workspace(['id' => 7]))->shared()->uniqueBy('pdf')->dispatch();

        return ['id' => $run->id];
    });

    $first = getJson('/docket-test/shared-report')->assertOk()->json('id'); // console context: starts the run

    // Testbench always reports runningInConsole(), so the request is served with the console flag cleared.
    $response = asHttpRequest(fn () => getJson('/docket-test/shared-report'));

    $response->assertForbidden();
    expect($response->json('id'))->toBeNull()
        ->and(Run::query()->where('name', 'public.report')->pluck('id')->all())->toBe([$first]);
});
