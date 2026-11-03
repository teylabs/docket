<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;
use Tey\Docket\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('private');
    actingAs(new User(['id' => 1]));
});

/** @param  array<string, mixed>  $attributes */
function outputRun(array $attributes = []): Run
{
    return Run::create(['name' => 'report.build', 'status' => RunStatus::Succeeded, 'initiator_type' => User::class, 'initiator_id' => '1', 'finished_at' => now(), ...$attributes]);
}

it('records the file a run produced and offers it in the snapshot without its location', function () {
    Storage::disk('private')->put('builds/a1.pdf', '%PDF-1.7 report');
    $run = outputRun()->output('builds/a1.pdf', 'private', 'Report "draft".pdf', 'application/pdf');

    $snapshot = $run->fresh()->toArray();
    expect($snapshot['output'])->toBe(['name' => 'Report -draft-.pdf', 'mime' => 'application/pdf', 'size' => 15])
        ->and(json_encode($snapshot))->not->toContain('builds/a1.pdf', 'private')
        ->and($snapshot['links'])->toHaveKeys(['download', 'view']);
});

it('refuses an output that is not on the disk', function () {
    outputRun()->output('missing.pdf', 'private');
})->throws(InvalidArgumentException::class);

it('downloads the output as an attachment and views it inline, streamed from the disk', function () {
    Storage::disk('private')->put('builds/a1.pdf', '%PDF-1.7 report');
    $links = outputRun()->output('builds/a1.pdf', 'private', 'Report.pdf', 'application/pdf')->toArray()['links'];

    $download = get($links['download'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment; filename=Report.pdf')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store', 'private')
        ->and($download->streamedContent())->toBe('%PDF-1.7 report');
    expect(get($links['view'])->assertOk()->headers->get('Content-Disposition'))->toStartWith('inline; filename=Report.pdf');
});

it('never opens a type the browser could run in the page, only downloads it', function () {
    Storage::disk('private')->put('builds/page.html', '<script>alert(1)</script>');
    $run = outputRun()->output('builds/page.html', 'private', 'page.html', 'text/html');
    expect($run->toArray()['links'])->toHaveKey('download')
        ->and(array_key_exists('view', $run->toArray()['links']))->toBeFalse();

    $view = URL::temporarySignedRoute('docket.output.view', now()->addMinute(), ['token' => $run->token]);
    expect(get($view)->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('needs a valid, unexpired signature, the view policy and a live run', function () {
    Storage::disk('private')->put('builds/a1.pdf', 'x');
    $run = outputRun()->output('builds/a1.pdf', 'private');
    $download = $run->toArray()['links']['download'];

    get($download.'x')->assertForbidden();
    get(route('docket.output', ['token' => $run->token]))->assertForbidden();

    actingAs(new User(['id' => 2]));
    get($download)->assertNotFound();

    actingAs(new User(['id' => 1]));
    $run->forceFill(['expires_at' => now()->subSecond()])->save();
    get($download)->assertNotFound();

    $fresh = outputRun()->output('builds/a1.pdf', 'private');
    $link = $fresh->toArray()['links']['download'];
    travel(config('docket.output_link_ttl') + 1)->seconds();
    get($link)->assertForbidden();
});

it('answers not found when the run has no output or the file is gone', function () {
    $run = outputRun();
    expect($run->toArray()['output'])->toBeNull()->and($run->toArray()['links'])->not->toHaveKey('download');
    get(URL::temporarySignedRoute('docket.output', now()->addMinute(), ['token' => $run->token]))->assertNotFound();

    Storage::disk('private')->put('builds/a1.pdf', 'x');
    $run->output('builds/a1.pdf', 'private');
    Storage::disk('private')->delete('builds/a1.pdf');
    get($run->toArray()['links']['download'])->assertNotFound();
});

it('follows a run whose policy only allows viewing', function () {
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    Storage::disk('private')->put('builds/a1.pdf', 'x');
    get(outputRun()->output('builds/a1.pdf', 'private')->toArray()['links']['download'])->assertOk();
});
