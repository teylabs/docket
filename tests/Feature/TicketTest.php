<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Ticket;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('serializes exactly the ticket fields across JSON arrays and queued payloads', function () {
    $run = Run::create(['name' => 'export.build', 'checkpoint' => 'private-position', 'result' => 'private-result', 'callbacks' => 'private-callbacks']);
    $run->items()->create(['key' => 'private-item', 'result' => 'private-reference']);
    $ticket = $run->ticket();
    $poll = 'http://localhost/docket/runs/'.$run->token;
    $expected = [
        'schema_version' => 1, 'id' => $run->id, 'token' => $run->token, 'name' => 'export.build',
        'status' => 'queued', 'revision' => 1, 'poll_after_ms' => 2000, 'links' => ['poll' => $poll],
    ];
    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket->run()->is($run))->toBeTrue()
        ->and($ticket->toArray())->toBe($expected)
        ->and($ticket->jsonSerialize())->toBe($expected)
        ->and(json_decode(json_encode($ticket), true))->toBe($expected)
        ->and(unserialize(serialize($ticket))->toArray())->toBe($expected)
        ->and(serialize($ticket))->not->toContain('private-')
        ->and($ticket)->not->toBeInstanceOf(Stringable::class);
});

it('returns 202 while unfinished and 200 once finished with Location and private no-store headers', function (RunStatus $status) {
    $ticket = Run::create(['name' => 'export.build', 'status' => $status])->ticket();
    Route::get('ticket', fn () => $ticket);
    $expectedStatus = $status->isFinished() ? 200 : 202;
    getJson('ticket')->assertStatus($expectedStatus)->assertExactJson($ticket->toArray())
        ->assertHeader('Location', $ticket->toArray()['links']['poll']);
    $response = $ticket->toResponse(Request::create('ticket'));
    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($ticket->toArray()['poll_after_ms'])->toBe($status->isFinished() ? null : 2000);
    get('ticket')->assertStatus($expectedStatus)->assertJson($ticket->toArray());
})->with(RunStatus::cases());

it('keeps the original status and revision but resolves permissions for the current user', function () {
    $run = Run::create(['name' => 'export.build']);
    $ticket = $run->ticket();
    Gate::define('cancel', fn (?object $user, Run $run) => true);
    expect($ticket->toArray()['links'])->toHaveKey('cancel');
    Gate::define('cancel', fn (?object $user, Run $run) => false);
    $run->update(['status' => RunStatus::Failed]);
    Gate::define('retry', fn (?object $user, Run $run) => true);
    expect($ticket->toArray()['links'])->not->toHaveKeys(['cancel', 'retry'])
        ->and($ticket->status)->toBe(RunStatus::Queued)
        ->and($ticket->revision)->toBe(1)
        ->and($run->ticket()->toArray()['links'])->toHaveKey('retry');
    Gate::define('retry', fn (?object $user, Run $run) => false);
    expect($run->ticket()->toArray()['links'])->not->toHaveKey('retry');
    $run->delete();
    expect(fn () => $ticket->run())->toThrow(ModelNotFoundException::class);
});
