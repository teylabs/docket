# Using Docket in This App

Docket wraps a piece of work in a run: a durable record that it happened, with a ticket a page, console or API client can follow. Use it when someone needs to see that the work happened, how it is going or how it ended. Don't wrap fire-and-forget work nobody reads.

## Start a Run

- Inline: `Docket::run('area.action', fn (Run $run) => ...)` runs the closure now and returns a `Tey\Docket\Ticket`. `$ticket->result()` is the closure's return value, in this process only. Options go first: `Docket::about($model)->run(...)`.
- Exceptions from the closure fail the run and are rethrown unchanged; don't catch them just for Docket.
- A `Docket::run()` inside another run is a child run; it rolls up into the parent. A child's uncaught exception fails the parent (`run.child_failed`); a caught one leaves the parent `partial`. Use `step()` for phases of one run, items for its rows, and child runs for separate pieces of work.

- Dispatch with `Docket::job($job)`, `Docket::batch($jobs)` or `Docket::chain($jobs)`, then `->dispatch()`. You get a `Tey\Docket\Ticket`.
- Set `->about($model)` when the work concerns a record; the model uses `Tey\Docket\HasRuns`.
- Return the ticket from the controller (a 202 JSON response, or a redirect to the watch page for a plain browser form). Pass `$model->latestRun?->ticket()` as an Inertia prop or Livewire property, or echo it in Blade with `{{ $ticket }}` to render progress. Use `$ticket->url()` for a link; a ticket is not a string.
- Use `->unique()` for "only one at a time per record", and `->idempotencyKey($key)` for requests a client may repeat.
- Give a stable `->name()` to runs you query by name, and to every closure run.
- Never let request input choose a job class. Each dispatching route is app code.

## Report From the Work

- Inside `run()`, use the closure's `$run`. In a queued job, add `use Tey\Docket\Docketable;` and call `$this->docket()`. Anywhere deeper (observers, services, listeners), use `Docket::current()?->log(...)`; it is `null` outside a run, so always use `?->`.
- `Docket::job(...)->dispatch()` inside a run is a child run, and the parent waits for it unless it is `->detached()`. `$run->add($job)` makes a job part of the current run instead. A plain `dispatch()` is never tracked.
- Continuing the same work (the next page, the next slice) uses `$run->add($job)`: the same run at the same depth, never a child. Unrelated follow-up work that would nest too deep starts fresh with `->linkedTo(Docket::current())`. Never nest a job inside itself.
- Loop with `$run->track($rows, key: fn ($row) => "row:{$row->line}")`. Record only failures and skips; success is implicit.
- Item keys must be stable across retries: a row number or record id, never a timestamp or index into a changing list.
- Use `$run->fail('area.reason')` for an error the user can act on. Let other exceptions throw.
- Batched jobs that are one unit each define `docketKey()`.
- Make side effects safe to repeat: `retry()` and queue retries run them again.

## Never Put in Codes, Log Params, Results or Checkpoints

- Content: row values, file text, message bodies, names, emails, addresses or any personal data.
- Secrets: tokens, API keys, writer grants, capability secrets, signed URLs.
- Exception messages or stack traces.

Use ids, row numbers, counts and short `area.reason` codes. Results are references (an id or a storage path the app resolves), never the content itself.

## Access

- `Docket::find($token)` doesn't check access. Call `Gate::authorize('view', $run)` in app routes.
- Change who can see runs in `App\Policies\RunPolicy`, registered with `Gate::policy(Run::class, ...)`.
- Never add writer grants, capability secrets or the checkpoint to a ticket, prop or response. `$ticket->result()` is in-process only; don't put it in a response unless it is safe to show.

## Test

- `Docket::fake()` plus `Docket::assertDispatched(Job::class, fn (PendingRun $run) => ...)` or `Docket::assertRan('area.action')` in feature tests. A faked `run()` still runs its closure.
- `[$job, $run] = (new Job(...))->withFakeDocket(); $job->handle(); $run->assertItemFailed('row:2', 'row.invalid');` in unit tests.
