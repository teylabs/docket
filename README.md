# Docket: Run Anything, Keep the Record

[![Tests](https://img.shields.io/github/actions/workflow/status/teylabs/docket/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/docket/actions/workflows/run-tests.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/teylabs/docket/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/docket/actions/workflows/fix-php-code-style-issues.yml)

> [!NOTE]
> Docket is in early development and has no release yet. This README describes the planned API, accepted as the design for 0.1–0.3. [docs/reference.md](docs/reference.md) tags every entry with the milestone it lands in. Until 0.1 is tagged, nothing below is guaranteed to work.
> The runs, items and logs schema, model reads, status snapshot, queued dispatch, inline runs, in-process results, the current run, JSON tickets, reporting from the work, run trees with roll-up, depth and exact-repeat protection, and linked top-level runs are implemented. Cancel/retry controls, pruning, testing fakes and package routes are not implemented yet; the reference tracks what is available.

Docket wraps any piece of work in your Laravel app, from one statement to a queued import of a million rows, in a run: a durable record that it happened, with a ticket you can hand to a page, a console or an API client. Code running inside the work, down to a model observer, can report to the run as it goes. Long work usually means a spinner, a status column, a progress table and a polling endpoint written for each feature. With Docket, each one reads the same status, even after a reload, and can retry only the rows that failed.

```php
// app/Http/Controllers/ProfileController.php
use Tey\Docket\Facades\Docket;

public function update(UpdateProfileRequest $request, Profile $profile)
{
    Docket::run('profile.update', fn () => $profile->update($request->validated()));

    return back();
}
```

That one call leaves a run behind (who started it, when, how long it took and how it ended) and returns a ticket for it. Queue the work instead, and the same kind of ticket comes back at once while the work carries on:

```php
// app/Http/Controllers/ReportController.php
use App\Jobs\BuildReport;
use Tey\Docket\Facades\Docket;

public function store(Report $report)
{
    return Docket::job(new BuildReport($report))->about($report)->dispatch();
}
```

```json
HTTP/1.1 202 Accepted

{
  "schema_version": 1,
  "id": "01JA8Z3K6M2X9QH7T4V5B0N1CD",
  "token": "7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq",
  "name": "App\\Jobs\\BuildReport",
  "status": "queued",
  "revision": 1,
  "poll_after_ms": 2000,
  "links": {
    "poll": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq",
    "cancel": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq/cancel"
  }
}
```

> [!NOTE]
> Docket is pre-1.0. Minor releases may change the API until 1.0.

- **Wrap anything:** a closure run inline, a job, batch or chain on the queue, or a console command. [Sources and Watchers](#sources-and-watchers)
- **Report from anywhere inside:** `Docket::current()` reaches the active run from observers, services and listeners. [The Current Run](#the-current-run)
- **Track queued work** with the `Bus` calls you already know. [Dispatching Work](#dispatching-work)
- **One ticket, every surface:** return it from a controller, pass it as a prop, or echo it in Blade, and it renders the right thing. [One Ticket, Every Surface](#one-ticket-every-surface)
- **Report row-level outcomes** from inside the work, including failures, skips and a resumable checkpoint. [Reporting From the Work](#reporting-from-the-work)
- **Retry only what failed** in a new linked run that skips rows which already succeeded. [Cancelling and Retrying](#cancelling-and-retrying)
- **Show progress** in Vue, React, Blade, Livewire or the console from one status format. [Showing Progress](#showing-progress)
- **Keep it private and tidy:** policy-checked reads, references instead of content, and runs that expire. [Authorization](#authorization), [Retention](#retention)

## Contents

- [Installation](#installation)
- [In 30 Seconds](#in-30-seconds)
- [Concepts](#concepts)
- [Sources and Watchers](#sources-and-watchers)
- [Nested Runs](#nested-runs)
- [Watching a Tree](#watching-a-tree)
- [Running Code Inline](#running-code-inline)
- [Dispatching Work](#dispatching-work)
- [One Ticket, Every Surface](#one-ticket-every-surface)
- [Reporting From the Work](#reporting-from-the-work)
- [Reading Runs](#reading-runs)
- [Cancelling and Retrying](#cancelling-and-retrying)
- [Showing Progress](#showing-progress)
- [Console](#console)
- [Authorization](#authorization)
- [Retention](#retention)
- [Testing](#testing)
- [Before Production](#before-production)
- [Configuration](#configuration)
- [FAQ](#faq)

## Installation

Docket requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require tey/docket

php artisan vendor:publish --tag=docket-migrations
php artisan migrate
# -> creates docket_runs, docket_items and docket_logs
```

Inline runs need no queue. Queued runs work with any queue driver that runs jobs in the background, including `database`. See [Before Production](#before-production) for the scheduled command Docket needs.

## In 30 Seconds

Wrap any code in `Docket::run()`. It runs the closure right away, records the run and returns a ticket, just like a queued dispatch :

```php
// app/Http/Controllers/ProfileController.php
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;

public function update(UpdateProfileRequest $request, Profile $profile)
{
    Docket::about($profile)->run('profile.update', function (Run $run) use ($request, $profile) {
        $profile->update($request->validated());
        $run->log('profile.updated', ['fields' => count($request->validated())]);
    });

    return back();
}
```

With `HasRuns` on the model (shown next), `$profile->latestRun` is now a record of that update: its status, who started it, when it ran, its log, and a ticket you can show. If the closure throws, the run is recorded as failed and the exception carries on as usual.

Long work goes on the queue. Add `HasRuns` to the model the work is about:

```php
// app/Models/Upload.php
use Tey\Docket\HasRuns;

class Upload extends Model
{
    use HasRuns;
}
```

Dispatch the job with Docket instead of `dispatch()`:

```php
// app/Http/Controllers/UploadController.php
use App\Jobs\ImportRows;
use Tey\Docket\Facades\Docket;

public function store(StoreUploadRequest $request)
{
    $upload = $request->user()->uploads()->create([
        'path' => $request->file('file')->store('uploads'),
    ]);

    Docket::job(new ImportRows($upload))->about($upload)->dispatch();

    return to_route('uploads.show', $upload);
}
```

Report from the job with the `Docketable` trait:

```php
<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Tey\Docket\Docketable;

class ImportRows implements ShouldQueue
{
    use Docketable, Queueable;

    public function __construct(public Upload $upload) {}

    public function handle(): void
    {
        $run = $this->docket()->step('import');

        foreach ($run->track($this->upload->rows(), key: fn ($row) => "row:{$row->line}") as $key => $row) {
            if (! $row->isValid()) {
                $run->itemFailed($key, code: 'row.invalid');

                continue;
            }

            $this->upload->records()->create($row->attributes());
            // No call needed: a row the loop moves past without an outcome counts as succeeded.
        }
    }
}
```

Show it on the page:

```php
// app/Http/Controllers/UploadController.php
use Inertia\Inertia;

public function show(Upload $upload)
{
    return Inertia::render('Uploads/Show', [
        'upload' => $upload,
        'run' => $upload->latestRun?->ticket(),
    ]);
}
```

```vue
<!-- resources/js/pages/Uploads/Show.vue -->
<script setup>
import { DocketProgress, useDocketPoll } from '@/docket'

const run = useDocketPoll('run')
</script>

<template>
  <DocketProgress :run="run" />
</template>
```

```text
import · 340 of 1,200 · 3 failed
```

The bar keeps updating until the run finishes, and it picks up where it was after a reload.

## Concepts

| Term | Meaning |
| --- | --- |
| Run | One tracked piece of work: a closure run inline, a queued job, batch, chain or closure, or a console command. A run is an Eloquent model, `Tey\Docket\Run`, and it stays after the work ends as the record that it happened. |
| Source | Where a run starts: `Docket::run()`, `Docket::job()`/`batch()`/`chain()`/`dispatch()`, or (from 0.3) an external worker. |
| Current run | The run whose work is executing right now, from `Docket::current()`. Code deep inside the work reports to it without being passed the run. |
| Ticket | What `run()` and every dispatch return, and what `$run->ticket()` gives for any run: the run's id, token, name, status and links. Return it, pass it as a prop or echo it in Blade, and it renders as JSON, a redirect or a progress bar to suit (see [One Ticket, Every Surface](#one-ticket-every-surface)). |
| Item | One unit of work inside a run, such as a row, page or record, with a stable key like `row:8`. Each item finishes as `succeeded`, `failed` or `skipped`. |
| Step | A label for the stage the run is in, such as `validate`. |
| Child run | A run started inside another run: a nested `Docket::run()`, or `Docket::job()` dispatched inside a run. It has its own status, logs and ticket, and rolls up into its parent. |
| Subject | The model the run is about, set with `->about($model)`. |
| Token | An unguessable string that finds a run again. It locates the run; it doesn't grant access. |

A run's status is one of `queued`, `running`, `succeeded`, `partial`, `failed` or `cancelled`. An inline run starts at `running`. A run that finishes with any failed or skipped items is `partial`. Progress counts work attempted, not work that succeeded, so `partial` at 100% is accurate.

## Sources and Watchers

Work enters Docket from any of these sources. Each one makes the same kind of run, so everything that reads runs works with all of them.

**Inline code.** `Docket::run()` runs a closure now, in this process, and returns a ticket. See [Running Code Inline](#running-code-inline).

```php
$ticket = Docket::run('report.build', fn (Run $run) => $builder->build($run));
```

**The queue.** `Docket::job()`, `batch()`, `chain()` and `dispatch()` mirror `Bus` and return a ticket at once. See [Dispatching Work](#dispatching-work).

```php
$ticket = Docket::job(new ImportRows($upload))->about($upload)->dispatch();
```

**Console commands.** Wrap a command's `handle()` in `Docket::run()`, with an optional progress bar. See [Console](#console).

```php
Docket::withProgressBar($this->output)->run('legacy.import', fn (Run $run) => $this->import($run));
```

**External workers** (0.3). A worker outside Laravel, such as a Python page processor, reports to its run through a signed, expiring writer grant. See [Writer Grants](#writer-grants).

Every run, from any source, can be followed by any of these **watchers**:

| Watcher | How | See |
| --- | --- | --- |
| A page | `<DocketProgress>` or `<DocketTree>` in Vue or React, `{{ $ticket }}` in Blade, a Livewire property, or the watch page | [Showing Progress](#showing-progress) |
| An API client or agent | The ticket's JSON, then its `poll` link | [The Status Snapshot](#the-status-snapshot) |
| A terminal | `php artisan docket:watch {token or name}`, which shows the run's children too | [Console](#console), [Watching a Tree](#watching-a-tree) |
| Your code | `RunDispatched`, `ItemFinished` and `RunFinished` events, or Eloquent queries | [Events](#events), [Reading Runs](#reading-runs) |

## Nested Runs

A `Docket::run()` inside another run starts a **child run**. Runs form a tree: each child knows its parent and its root, has its own status, logs and ticket, and rolls up into its parent.

```php
Docket::run('catalog.sync', function (Run $run) {
    Docket::run('catalog.fetch', fn () => $this->fetch());         // a child run
    Docket::run('catalog.reconcile', fn () => $this->reconcile()); // another child run
});
```

A service that wraps its own work in `Docket::run()` is a run of its own when called directly, and a child when called from inside another run, without knowing its caller. Inside a queued job dispatched through Docket, a `Docket::run()` is a child of the job's run. A queued job dispatched with `Docket::job()` inside a run is a child too (see [From Inside a Run](#from-inside-a-run)). A plain `dispatch()` stays untracked.

`Docket::current()` is always the innermost run. Walk the tree with `$run->parent()`, `$run->root()` and `$run->children()`. Nesting is capped at 10 levels by default (`docket.max_depth`; `null` turns the cap off). See [Depth, Links and Continuation](#depth-links-and-continuation).

### Steps, Items and Child Runs

A **step** is a label for the phase one run is in. **Items** are many units of the same work inside one run, such as rows. A **child run** is a distinct piece of nested work, with its own status, logs and ticket. Use a step to say where a run is, items to count its rows, and child runs for work that should stand on its own.

### Roll-Up

- **Progress.** A parent's `done` and `total` are its own items plus each child's `done` and `total`. A child with no total counts as one unit: 0 of 1 while it runs, 1 of 1 once it finishes.
- **Running.** A parent stays `running` while any child is running, including queued children it dispatched, so `then`, `finally` and `RunFinished` wait for the whole tree. 
- **Partial.** A parent ends `partial` if any of its own items failed or were skipped, if a child ended `partial`, or if a child failed and the parent's code caught the exception.
- **Queued children.** A queued child's failure can't reach the parent's code, so it counts as handled: the parent ends `partial`, not `failed`.
- **Failed.** A parent ends `failed` if a child's exception went uncaught out of the parent's closure. Its error is `run.child_failed`, with the child's id in `error.params.child`.

### When a Child Fails

A child that throws is recorded as `failed`, and the exception is rethrown, like any run (see [Exceptions](#exceptions)). Catch it and the parent carries on, showing that child as failed; leave it and the parent fails too:

```php
Docket::run('catalog.sync', function (Run $run) {
    try {
        Docket::run('catalog.fetch', fn () => $this->fetch());
    } catch (FeedUnavailable) {
        // The child is failed; the parent carries on and will end partial.
    }

    Docket::run('catalog.reconcile', fn () => $this->reconcile()); // uncaught: the parent fails with run.child_failed
});
```

### Depth, Links and Continuation

A `Docket::run()` or `Docket::job()` that would go deeper than `docket.max_depth` throws `Tey\Docket\Exceptions\DepthExceeded` instead of starting. The attempt is recorded as a failed child with the code `run.depth_exceeded`, and it rolls up like any child failure: handled if you catch it, and otherwise the parent fails with `run.child_failed`. The cap fails fast; it never leaves anything waiting.

When nested work genuinely needs to start fresh, link it rather than nest it. `->linkedTo($run)` starts a new top-level run at depth 0 that points back at the original:

```php
Docket::job(new ReconcileArchive($archive))
    ->linkedTo(Docket::current())
    ->dispatch();
```

The original lists it under `$run->linked()`, and its snapshot links to it, but a linked run never holds the original open and never counts in its roll-up. The new run has its own tree, its own depth and its own ticket. `$linked->linkedFrom()` leads back.

Continuing the same work is not nesting. A job that pages through an external API, or works through a long list in slices, should add the next slice to its own run, which keeps the same run and the same depth:

```php
public function handle(Client $client): void
{
    $run = $this->docket();
    $page = $client->profiles(page: $this->page);

    foreach ($run->track($page->items, key: fn ($p) => "profile:{$p->id}") as $profile) {
        $this->sync($profile);
    }

    if ($page->hasMore()) {
        $run->add(new SyncProfiles($this->page + 1)); // same run, same depth: never a child
    }
}
```

The rule: **continuing the same work → `$run->add()`; distinct nested work → a child; unrelated follow-up work → `->linkedTo()`.**

### Repeated Work

The depth cap can't catch a loop that never nests. A paged sync stuck on the same cursor keeps adding "the next page" to its own run, at depth 1, forever. So Docket fingerprints every unit of work it starts inside a run and fails a branch the moment it repeats exactly.

A unit's fingerprint is its `docketKey()` when the job defines one. Otherwise it is the job class plus a stable hash of its serialized input: models hash by class and key, associative arrays hash with their keys sorted, and lists keep their order. A `Docket::run()` child is fingerprinted by its name and the file and line of its closure.

- **Continuation.** If `$run->add($job)` adds a fingerprint the run has already added, the add throws `Tey\Docket\Exceptions\LoopDetected`, the run fails with `run.loop_detected`, and nothing more is queued.
- **Recursion.** If a child (a nested `run()` or a `Docket::job()` inside a run) has the same fingerprint as any of its ancestors, the child is recorded failed with `run.loop_detected` and rolls up like any child failure: handled if caught, and otherwise the parent fails with `run.child_failed`.
- `error.params` names the job class and the run or continuation it repeats. Docket fires `LoopDetected` and reports the exception to your exception handler, so your error tracker alerts.

```text
 profiles.sync    failed   loop detected: SyncProfiles repeats continuation #2 (same input)
```

`docket:watch` and `<DocketTree>` flag the failed branch in red.

When identical work really should run again inside the same run, such as a deliberate second pass over the same input, say so: `$run->add($job, allowRepeat: true)` or `Docket::job($job)->allowRepeat()->dispatch()`. Prefer giving the work a distinguishing input, such as a pass number or cursor, so that real loops are still caught. A `retry()` is never a repeat: it is a new top-level run (`retry_of_run_id`) with its own history. Neither is `$this->release()`, which is another attempt of the same job.

0.2 adds warn-only signals for loops that aren't exact repeats: many continuations without `done` moving, and runaway growth in a run's children or items. These are tuned on real data and never fail a run.

### Dockets Never Hang

- A parent waits only for its own non-detached children, never for linked runs.
- A child that dies silently (a killed worker, a lost job) is failed as `run.stalled` once `staleAfter` passes, by `docket:prune`, so its parent finishes.
- The depth cap fails fast with `run.depth_exceeded` instead of creating more runs.
- An exact repeat of work, by continuation or recursion, fails fast with `run.loop_detected`.
- Linked and detached runs never hold anything open.

So every run reaches a final status, as long as `docket:prune` is scheduled (see [Before Production](#before-production)).

### Tickets in a Tree

Every run in the tree has its own ticket: `run()` returns the child's ticket, and `$child->ticket()` works for any stored child. The root's snapshot summarizes its children (`children.total`, `running`, `succeeded`, `partial`, `failed`) and links to a page of their tickets. It never includes a child's content. So one ticket at the top is enough to show the whole tree's progress.

## Watching a Tree

A root run can start many children that each go at their own pace. Here, a root dispatches 10 queued jobs, and each one becomes a child run with its own progress:

```php
// app/Console/Commands/RebuildShards.php, handle()
$ticket = Docket::run('shards.rebuild', function (Run $run) {
    foreach (range(1, 10) as $shard) {
        Docket::job(new RebuildShard($shard))->name("shard.{$shard}")->dispatch();
    }
});
```

The closure returns at once, and the root stays `running` until all 10 children finish (see [Roll-Up](#roll-up)). The same works from a queued root job.

### A Generic Loop

Every run has the same shape, so one loop reports on any tree with no code per job type:

```php
$root = $ticket->run();

foreach ($root->children as $child) {
    echo "{$child->name}  {$child->status->value}  {$child->progress}\n";

    foreach ($child->logs()->latest()->limit(5)->get() as $entry) {
        echo "  {$entry->code}\n";
    }
}
```

`$run->progress` prints as `620 of 1,000 (62%)`. `logs()` reads the activity log; `log()` writes to it. For deeper trees, `$root->descendants()` returns every run below the root, and `$root->tree($user)` returns the whole tree as nested arrays, keeping only the runs `$user` may view.

### In the Console

`docket:watch` prints the tree live: the root line with its roll-up, then one line per child with its status, progress and latest log entry:

```bash
php artisan docket:watch shards.rebuild
```

```text
 shards.rebuild   running     5,720 of 10,000 (57%)   children 10 · 6 running · 3 succeeded · 1 failed
 ├ shard.1        succeeded   1,000 of 1,000 (100%)   shard.indexed
 ├ shard.2        succeeded   1,000 of 1,000 (100%)   shard.indexed
 ├ shard.3        succeeded   1,000 of 1,000 (100%)   shard.indexed
 ├ shard.4        failed        410 of 1,000 (41%)    run.failed
 ├ shard.5        running       820 of 1,000 (82%)    rows.staged
 ├ shard.6        running       640 of 1,000 (64%)    rows.staged
 ├ shard.7        running       500 of 1,000 (50%)    rows.staged
 ├ shard.8        running       300 of 1,000 (30%)    rows.staged
 ├ shard.9        running        50 of 1,000 (5%)     shard.started
 └ shard.10       queued          0 of 1,000 (0%)
```

It redraws until the root finishes, then prints the final roll-up. Pass `--depth=2` to show grandchildren.

### In Vue and React

`<DocketTree>` shows the root's progress bar with a live list of its children underneath:

```vue
<!-- resources/js/pages/Shards/Show.vue -->
<script setup>
import { DocketTree, useDocketPoll } from '@/docket'

const run = useDocketPoll('run')
</script>

<template>
  <DocketTree :run="run" />
</template>
```

```text
shards.rebuild · 5,720 of 10,000 · 1 failed
[▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░] 57%

  ✓ shard.1    done                     shard.indexed
  ✓ shard.2    done                     shard.indexed
  ✓ shard.3    done                     shard.indexed
  ✕ shard.4    failed at 41%            run.failed
  ● shard.5    [▓▓▓▓▓▓▓▓░░] 82%         rows.staged
  ● shard.6    [▓▓▓▓▓▓░░░░] 64%         rows.staged
  ● shard.7    [▓▓▓▓▓░░░░░] 50%         rows.staged
  ● shard.8    [▓▓▓░░░░░░░] 30%         rows.staged
  ● shard.9    [░░░░░░░░░░] 5%          shard.started
  ○ shard.10   queued
```

It polls the root's `links.poll` and its `links.children` page, which lists each child's status, progress and latest log entry in one request. `<DocketTree>` is its own component rather than a `children` option on `<DocketProgress>`: `<DocketProgress>` stays one bar and one request that is safe on any page, while the tree makes the extra children request only where you place it, and its row list is a separate file you can restyle or delete. The React export has the same name.

### What Watchers Can See

Every read is policy-checked: the children page and `tree()` include only the children the viewer may `view`, and the root's `children` counts are the only trace of the rest. Child logs are never part of a ticket's serialized form. Watchers fetch them, paginated, through `links.children` and each child's `links.logs`.

## Running Code Inline

`Docket::run()` takes a name and a closure. The closure receives the `Run` and runs at once, and `run()` returns the run's ticket:

```php
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;

$ticket = Docket::run('cache.warm', function (Run $run) {
    $run->step('products');

    return Cache::warmProducts();
});

$count = $ticket->result(); // what the closure returned
```

The name is optional: `Docket::run(fn () => ...)` is named `closure`, like a queued closure. To set options, start from the facade and finish with `run()`. Every option from [Options](#options) applies, plus `withProgressBar()`:

```php
Docket::about($profile)->by($user)->expiresAt(now()->addYear())->run('profile.update', fn () => $profile->update($attributes));
```

The run's records are written on Docket's own database connection, so they are there even if your code's transaction rolls back (see [Inline Runs Inside a Transaction](#inline-runs-inside-a-transaction)).

### What `run()` Returns

`run()` returns a `Ticket`, the same as `dispatch()`, so every source hands back one type. By the time `run()` returns, the work is done, so the ticket's status is final (or `running` inside an open transaction, see below). Return it from an API controller and the client gets `200` with the ticket JSON:

```php
// app/Http/Controllers/Api/ExportController.php
public function store(Export $export)
{
    return Docket::about($export)->run('export.build', fn () => $export->build());
}
```

The closure's return value is one call away with `$ticket->result()`:

```php
$path = Docket::run('export.build', fn () => $export->build())->result();
```

`result()` exists only in the process that ran the work. It is held on that ticket object and is never serialized or stored: it isn't in the ticket's JSON, a page prop, a Livewire property or a queued payload. On a queued dispatch's ticket, `result()` throws `ResultNotAvailable`, because queued work finishes later; read `$ticket->run()->result` once it has finished. To keep a result with the run, record a reference with `$run->result($fileId)` as usual, never the content itself.

### Exceptions

Any exception thrown by the closure marks the run `failed` with the code `run.exception`, logs the exception's class name (never its message, which may hold content), and is then rethrown unchanged. Your exception handler sees it exactly as it would without Docket.

To fail with a code your users can read, call `$run->fail('area.reason')`. Inside `run()`, `fail()` throws `Tey\Docket\Exceptions\RunFailed`, which `run()` records with your code and rethrows:

```php
Docket::run('file.convert', function (Run $run) use ($file) {
    if (! $converter->supports($file)) {
        $run->fail('file.unsupported');
    }

    $converter->convert($file);
});
```

### Inline Runs Inside a Transaction

When `run()` finishes inside an open database transaction, the run stays `running` until the transaction commits, then becomes `succeeded`. If the transaction rolls back, the run is marked `failed` with `run.rolled_back`. Either way the record survives, because Docket writes on its own connection (`docket.connection`).

### The Current Run

`Docket::current()` returns the innermost run whose work is executing right now, or `null` when there is none. It is set while a `Docket::run()` closure executes and while a `Docketable` job handles. Code deep inside the work can report without being passed the run:

```php
// app/Observers/ProfileObserver.php
use App\Models\Profile;
use Tey\Docket\Facades\Docket;

class ProfileObserver
{
    public function saved(Profile $profile): void
    {
        Docket::current()?->log('record.saved', ['id' => $profile->id]);
    }
}
```

Saved inside a run, the profile adds a log entry to that run. Saved anywhere else, `current()` is `null` and nothing happens, so the observer is safe everywhere.

The current run never leaks. It is scoped to the work: it is cleared when the closure returns or throws, and when the job finishes or fails, and it is reset between requests and between jobs, including under Octane and in long-running queue workers. Docket keeps a stack: a child run pushes onto it and pops when it finishes, even when it throws, so the parent is current again. The current run never crosses the queue by itself either (see [From Inside a Run](#from-inside-a-run)).

### Long-Running Processes

Docket is built to stay flat in queue workers, Horizon and Octane:

- **The stack always unwinds.** `run()` pops its run in a `finally`, on every exit path, exceptions included.
- **Nothing carries over between jobs or requests.** The stack is reset on `JobProcessed`, `JobFailed` and `JobExceptionOccurred`, and on Octane's request, task and tick boundaries, so no run is current in the next unit of work.
- **No static per-run state.** Runs, tickets and in-process results are never cached in static properties or singletons that outlive their work. A ticket's `result()` lives on that ticket object only, and is freed with it.
- **Roll-up is bounded.** A progress update writes the run itself plus one counter update per ancestor, so its cost is O(depth). The depth cap (`docket.max_depth`, 10 by default) bounds it. Ancestor counters change with atomic increments, without holding row locks across children.

## Dispatching Work

`Docket` mirrors `Bus`. Each call returns a pending run; `dispatch()` saves the run, queues the work and returns a ticket.

```php
use Tey\Docket\Facades\Docket;

$ticket = Docket::job(new ImportRows($upload))->dispatch();

$ticket = Docket::batch($units->map(fn ($unit) => new BuildUnit($unit)))->name('units.build')->dispatch();

$ticket = Docket::chain([new BuildFile($export), new UploadFile($export)])->name('export.deliver')->dispatch();

$ticket = Docket::dispatch(new ImportRows($upload)); // shortcut for job(...)->dispatch()
```

A batch can hold chains as well as jobs. A chain inside a batch counts as one item, keyed by its first job (see [Jobs That Are Items](#jobs-that-are-items)).

### Closures

```php
$ticket = Docket::job(function (Run $run) {
    $run->step('warm')->result(Cache::warm());
})->name('cache.warm')->dispatch();
```

A closure run is named `closure` unless you name it. Every run has its own token whatever its name, so the ticket always finds it. Name closures you'll want to query again.

### Naming

A job's run is named after the job class, such as `App\Jobs\ImportRows`. Use `->name()` for a stable name that survives a class rename, or for runs you query by name:

```php
Docket::job(new ExportList($filters))->name('list.export')->dispatch();
```

The name appears in the ticket's JSON. Set one for runs you return to API clients if you'd rather not expose a class name.

### Options

```php
$ticket = Docket::job(new ImportRows($upload))
    ->about($upload)                       // the subject: $upload->runs, $upload->latestRun
    ->by($user)                            // who started it; defaults to the signed-in user, null in the scheduler
    ->name('rows.import')
    ->unique()                             // an unfinished run with the same name and subject is returned instead
    ->idempotencyKey($request->header('Idempotency-Key')) // the same name and key return the same run until it expires
    ->total(500)                           // expected units, when known up front
    ->staleAfter(300)                      // fail as run.stalled after 300 seconds without a report
    ->expiresAt(now()->addDay())           // delete the run and its items after this time
    ->dispatch();
```

| Option | Default |
| --- | --- |
| `about($model)` | none |
| `by($user)` | the authenticated user; `null` in queues and the scheduler |
| `name($name)` | the job class, or `closure` for a closure |
| `unique()` | off. It matches on name and subject, so name any closure you make unique. |
| `idempotencyKey($key)` | none |
| `total($units)` | the number of items declared up front |
| `staleAfter($seconds)` | the job's `$timeout`, else `docket.stale_after` |
| `expiresAt($time)` | `docket.ttl` after the run finishes |

### Callbacks

```php
Docket::job(new ExportList($filters))
    ->then(fn (Run $run) => $run->initiator->notify(new ExportReady($run)))      // succeeded
    ->catch(fn (Run $run) => $run->initiator->notify(new ExportFailed($run)))    // failed
    ->finally(fn (Run $run) => Storage::delete($run->checkpoint()))              // any finish
    ->dispatch();
```

`then` runs when the run succeeds. `catch` runs once, when the run ends as failed (including `run.stalled`). `finally` runs on any finish: succeeded, partial, failed or cancelled.

> [!NOTE]
> `catch` differs from `Bus::batch()->catch()`, which runs on the first failed job. Docket's `catch` runs only when the whole run has failed.

### Returning a Ticket

A ticket is a response. Return it from a controller, and a request that asks for JSON gets `202 Accepted` with the ticket's JSON and a `Location` header pointing at the poll link. A browser that doesn't ask for JSON is redirected to the run's watch page:

```php
// app/Http/Controllers/Api/ImportController.php
public function store(Request $request)
{
    $upload = $request->user()->uploads()->create(['path' => $request->file('file')->store('uploads')]);

    return Docket::job(new ImportRows($upload))
        ->about($upload)
        ->idempotencyKey($request->header('Idempotency-Key'))
        ->dispatch();
}
```

The JSON is shown at the top of this README. A ticket carries the status at the moment it was made. Read the poll link for current progress.

Docket accepts no job class from the request: each route that dispatches is your own controller, so your routes are the allowlist of work a client or agent can start.

### Inside a Transaction

`dispatch()` inside `DB::transaction()` writes the run at once, on Docket's own connection, and queues the job after commit. If the transaction rolls back, the job is never queued and the run is marked `failed` with `run.rolled_back`, so the ticket still finds a record of what happened.

### From Inside a Run

A queued job dispatched with `Docket::job()` inside a run is a child run: linked to the current run, counted in its roll-up, and listed in its children:

```php
Docket::run('nightly.sync', function (Run $run) {
    $this->pullChanges();

    Docket::job(new RebuildSearchIndex)->dispatch(); // a child run
});
```

To make a queued job part of the current run instead, add it: `$run->add(new RebuildSearchIndex)`. An inline run with added jobs or queued children stays `running` after the closure returns, until they finish. To start a queued child the parent shouldn't wait for, dispatch it with `->detached()`: it is still linked and listed, but it doesn't hold the parent open or count in its status. A plain `dispatch()` stays untracked, as everywhere else: the current run never crosses the queue by itself.

## One Ticket, Every Surface

The same ticket renders itself wherever you put it:

| Where you put it | Interface | What you get |
| --- | --- | --- |
| Returned from a controller (API, agent or form post) | `Responsable` | `202` with the ticket JSON for requests that ask for JSON. A browser that doesn't is redirected to the run's watch page. |
| An Inertia prop | `JsonSerializable`, `Arrayable` | Exactly what `useDocketPoll` and `<DocketProgress>` take. |
| Blade: `{{ $ticket }}` | `Htmlable` | A `<x-docket-progress>` for the run. |
| A Livewire property | `Wireable` | It survives round trips; refresh progress with `wire:poll`. |
| Arrays, logs, queued jobs and events | `Arrayable` | The ticket fields, with the read token only. |

```blade
{{-- resources/views/uploads/show.blade.php --}}
{{ $upload->latestRun?->ticket() }}
```

```php
// app/Livewire/UploadStatus.php
use Livewire\Component;
use Tey\Docket\Ticket;

class UploadStatus extends Component
{
    public ?Ticket $ticket;

    public function mount(Upload $upload): void
    {
        $this->ticket = $upload->latestRun?->ticket();
    }
}
```

```blade
{{-- resources/views/livewire/upload-status.blade.php --}}
<div wire:poll.2s>
    {{ $ticket }}
</div>
```

For a link, use `$ticket->url()`: the watch page, or the poll link when the watch page is off. A ticket isn't a string, so `{{ $ticket }}` always renders the progress bar, never a URL.

Every surface carries the read token only. See [What a Ticket Contains](#what-a-ticket-contains).

## Reporting From the Work

Every reporting call below works the same in any source. Reach the run in whichever way is closest:

- inside `Docket::run()`, the closure's `Run $run` argument;
- in a queued job, the `Docketable` trait's `$this->docket()`. Like `$this->batch()`, it returns `null` when the job wasn't dispatched through Docket;
- anywhere else inside the work, `Docket::current()` (see [The Current Run](#the-current-run)).

### Tracking Items

`track()` declares items, gives each a key, and handles the loop's bookkeeping:

```php
// app/Jobs/ImportRows.php, handle()
$run = $this->docket()->step('validate');

foreach ($run->track($this->upload->rows(), key: fn ($row) => "row:{$row->line}") as $key => $row) {
    if ($row->isDuplicate()) {
        $run->itemSkipped($key, code: 'row.duplicate');

        continue;
    }

    $this->upload->records()->create($row->attributes());
}
```

For each item, `track()`:

- skips it if it already succeeded, in this run or in the run this one retries;
- checks for cancellation before yielding it;
- records success when the loop moves on, unless the body recorded an outcome.

A `break` leaves the current item unsettled.

To declare items you'll settle later, pass their keys: `$run->track(['step:build', 'step:deploy'])`.

### Recording Outcomes

```php
$run->itemSucceeded('row:9', code: 'created');           // a code on success groups summaries: created, updated
$run->itemFailed('row:10', code: 'row.invalid');
$run->itemSkipped('row:11', code: 'row.duplicate');
$run->itemSucceeded('part:3', result: $partId, count: 50); // count: how many units of the total this item stands for
```

Each item counts once, however often it is reported. Codes are short machine strings like `row.invalid`. Your UI turns them into words.

### Jobs That Are Items

In a batch, a job that defines `docketKey()` is itself an item. It succeeds when the job completes and fails when the job fails for the last time:

```php
// app/Jobs/BuildUnit.php
public function docketKey(): string
{
    return "unit:{$this->unit->id}";
}

public function handle(Builder $builder): void
{
    if ($this->unit->isLocked()) {
        $this->docket()->itemSkipped(code: 'unit.locked'); // the key defaults to this job's

        return;
    }

    $builder->build($this->unit);
}
```

A keyed job's failure fails only its item. Any other job's final failure fails the whole run: remaining work is cancelled, and finished items keep their results.

### Total, Step, Log and Result

```php
$run->total($count);                       // set or correct the expected units
$run->step('stitch');                      // the label the UI shows
$run->log('rows.staged', ['count' => 500]); // a code and scalar params, for the run's activity log
$run->result($fileId);                     // the run's output, as a reference
```

Without `total()`, the total is the number of items declared up front: keyed jobs in a batch, and items passed to `track()` as an array or collection. A lazy collection declares items as it reaches them, so call `total()` when you know the count. Until there is a total, progress is indeterminate rather than 0%.

> [!WARNING]
> Codes, log params and results are stored as given, and anyone who can view the run can read them. Record row numbers and ids, never row values, file text, personal data, secrets or exception messages.

### Checkpoints

A checkpoint is a small resumable position. A retry inherits it:

```php
// app/Jobs/ExportRange.php, handle()
$run = $this->docket()->step('export');
$from = $run->checkpoint() ?? 0;

foreach ($this->range->query()->where('id', '>', $from)->lazyById(500)->chunk(500) as $chunk) {
    $run->itemSucceeded("part:{$chunk->last()->id}", result: $this->writePart($chunk), count: $chunk->count());
    $run->checkpoint($chunk->last()->id);
}
```

### Adding Work

A running job can add more jobs to its own run. Keys that already succeeded are skipped:

```php
// app/Jobs/ExtractRows.php, handle()
$run = $this->docket()->step('extract');
$count = $this->upload->stageRows();

$run->step('validate')->total($count);
$run->add($this->upload->stagedIds()->chunk(100)->map(fn ($ids) => new ValidateRows($this->upload, $ids)));
```

### Failing a Run

`fail()` ends the run as `failed` with a code your users can read:

```php
if (! $reader->supports($this->upload)) {
    return $this->docket()->fail('file.unsupported');
}
```

In a queued job, `fail()` ends the job without a retry. Inside `Docket::run()`, it throws `RunFailed`, which `run()` records and rethrows (see [Exceptions](#exceptions)).

An uncaught exception after a queued job's last try fails the run with `run.failed`; an exception out of `Docket::run()` fails it with `run.exception`. A run that stops reporting for longer than `staleAfter()` fails with `run.stalled`. Docket reserves the `run.` prefix.

### Cancellation

`track()` checks for cancellation before each item. Outside `track()`, check at your own safe points:

```php
$run->throwIfCancelled();
```

## Reading Runs

A run is an Eloquent model:

```php
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;

$run = Docket::find($token);                // or $ticket->run()

$upload->latestRun;
$upload->runs()->named('rows.import')->latest()->get();
Run::named('units.build')->unfinished()->latest()->first();

$run->status;                               // Tey\Docket\RunStatus
$run->succeeded();                          // also finished(), partial(), failed(), cancelled()
$run->subject;                              // the model from about()
$run->initiator;                            // the user from by()
$run->result;                               // the reference from result()
$run->results();                            // item results, keyed by item key
$run->items()->failed()->cursorPaginate(50);
```

`Docket::find()` doesn't check access. Authorize in your own routes: `Gate::authorize('view', $run)`.

### The Status Snapshot

A run serializes to its status snapshot, never to its table columns:

```php
return response()->json($run);
```

```json
{
  "schema_version": 1,
  "id": "01JA8Z3K6M2X9QH7T4V5B0N1CD",
  "token": "7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq",
  "name": "rows.import",
  "status": "running",
  "revision": 42,
  "step": "validate",
  "progress": { "done": 340, "total": 1200, "percent": 28 },
  "counts": { "processed": 340, "succeeded": 337, "failed": 3, "skipped": 0 },
  "error": null,
  "result": null,
  "partial": [],
  "cancel_requested": false,
  "parent_run_id": null,
  "root_run_id": null,
  "retry_of_run_id": null,
  "children": { "total": 0, "running": 0, "succeeded": 0, "partial": 0, "failed": 0 },
  "created_at": "2026-10-13T14:02:11Z",
  "finished_at": null,
  "expires_at": null,
  "poll_after_ms": 2000,
  "links": {
    "poll": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq",
    "items": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq/items",
    "logs": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq/logs",
    "cancel": "https://example.test/docket/runs/7hQ2mV9xL4pR8sT1wY6zB3nC5fG0jK2aDq/cancel"
  }
}
```

Every field is described in [docs/json.md](docs/json.md).

### From a Stored Run to a Ticket

`$run->ticket()` turns any stored run back into a ticket, the same shape `dispatch()` returns. Use it for page props and list rows:

```php
return Inertia::render('Uploads/Index', [
    'uploads' => $request->user()->uploads()->with('latestRun')->latest()->paginate()
        ->through(fn (Upload $upload) => [
            'id' => $upload->id,
            'path' => $upload->path,
            'run' => $upload->latestRun?->ticket(),
        ]),
]);
```

### Package Routes

Tickets and snapshots link to routes that Docket registers. Each route runs your `Run` policy (see [Authorization](#authorization)):

| Route | Ability | Returns |
| --- | --- | --- |
| `GET /docket/runs/{token}` | `view` | the snapshot |
| `GET /docket/runs/{token}/items?status=failed` | `view` | cursor-paginated items |
| `GET /docket/runs/{token}/logs` | `view` | cursor-paginated logs |
| `GET /docket/runs/{token}/children` | `view` | cursor-paginated child tickets, each with progress and latest log (each child also checked) |
| `GET /docket/runs/{token}/watch` | `view` | the watch page (HTML) |
| `POST /docket/runs/{token}/cancel` | `cancel` | the snapshot |
| `POST /docket/runs/{token}/retry` | `retry` | a ticket for the new run, `202` |

### Events

| Event | Fired when |
| --- | --- |
| `Tey\Docket\Events\RunDispatched` | a run is saved and its work queued |
| `Tey\Docket\Events\ItemFinished` | an item succeeds, fails or is skipped |
| `Tey\Docket\Events\RunFinished` | a run reaches a final status (`$event->run->status`) |
| `Tey\Docket\Events\RunExpiring` | `docket:prune` is about to delete an expired run |
| `Tey\Docket\Events\LoopDetected` | a branch failed because its work repeated exactly (`$event->run`, `$event->repeats`) |

Each event has a stable `$event->id`, and delivery is at least once. Deduplicate on that id in anything that charges or counts.

## Cancelling and Retrying

```php
$run->cancel();
```

Cancelling is cooperative and safe to repeat. The run shows `cancel_requested` until the work reaches its next check, then finishes as `cancelled`. Work already done isn't undone. Cancelling a run also cancels its Laravel batch.

```php
$ticket = $run->retry()->dispatch();
```

`retry()` returns a pending run for a new run linked to the old one (`retry_of_run_id`). It re-runs the same work, skips items that already succeeded and inherits the checkpoint. This one call covers both "retry the failed rows" and "continue after a crash". Because retried work runs again, keep side effects safe to repeat.

## Showing Progress

Every surface reads the same snapshot.

### Vue and Inertia

```vue
<!-- resources/js/pages/Uploads/Show.vue -->
<script setup>
import { DocketProgress, useDocketPoll } from '@/docket'

const props = defineProps({ upload: Object, run: Object })
const run = useDocketPoll('run', { reloadOnFinish: ['upload'] })
</script>

<template>
  <DocketProgress :run="run" />
  <a v-if="run?.status === 'succeeded'" :href="route('uploads.download', upload)">Download</a>
</template>
```

`useDocketPoll('run')` reads the page prop `run`, which can be a ticket or a snapshot. It returns the live snapshot and polls the run's `poll` link at `poll_after_ms`, then stops once the run finishes. When the prop changes, after a retry redirect for example, it follows the new run.

`<DocketProgress>` shows the step, the progress, failed and skipped counts, an indeterminate bar while the total is unknown, and a final state. It announces step changes to screen readers and respects reduced motion.

### React

```jsx
// resources/js/pages/Uploads/Show.jsx
import { DocketProgress, useDocketPoll } from '@/docket'

export default function Show() {
  const run = useDocketPoll('run')

  return <DocketProgress run={run} />
}
```

### Blade

```blade
{{-- resources/views/uploads/show.blade.php --}}
{{ $upload->latestRun?->ticket() }}

{{-- or, to pass options, the component itself --}}
<x-docket-progress :run="$upload->latestRun" />
```

A ticket renders the run's current snapshot, then follows its poll link until the run finishes. A `Run` renders once.

### Livewire

Keep the ticket in a property and refresh with `wire:poll`, as shown in [One Ticket, Every Surface](#one-ticket-every-surface). Each round trip renders the current snapshot.

### Watch Page

A browser that posts a form to a controller returning a ticket lands on `/docket/runs/{token}/watch`: a plain page with the progress bar that follows the run, checked by the same `view` policy. Publish its view with `php artisan vendor:publish --tag=docket-views` to restyle it.

## Console

Wrap a command's work in `Docket::run()`, and add a progress bar with `withProgressBar()`:

```php
// app/Console/Commands/ImportLegacy.php
public function handle(LegacyReader $reader): int
{
    Docket::withProgressBar($this->output)->run('legacy.import', function (Run $run) use ($reader) {
        $rows = $reader->open($this->argument('path'));

        foreach ($run->step('import')->track($rows, key: fn ($row) => "row:{$row->line}") as $key => $row) {
            if (! $row->isValid()) {
                $run->itemFailed($key, code: 'row.invalid');

                continue;
            }

            $row->save();
        }
    });

    return self::SUCCESS;
}
```

The command's run is recorded like any other, so a page or `docket:watch` can follow it while it works. An exception fails the run and then reaches Artisan as usual. To run an existing job class in the command instead, `Docket::job($job)->withProgressBar($this->output)->dispatchSync()` does the same and returns a ticket.

```text
 legacy.import · import
 1200/1200 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%  12 secs  24 MiB

 Succeeded  1,188   Failed  12 (row.invalid 9, row.duplicate 3)   Skipped  0
```

Follow a queued run from any terminal by token or name:

```bash
php artisan docket:watch rows.import
# -> rows.import · validate · 340 of 1,200 · 3 failed
```

## Authorization

Docket checks access with a Laravel policy for `Tey\Docket\Run`. The policy it ships allows `view`, `cancel` and `retry` only for the run's initiator. Runs started from the scheduler have no initiator, so they are hidden until you register your own policy.

Most apps grant access through the subject:

```php
<?php

namespace App\Policies;

use App\Models\User;
use Tey\Docket\Run;

class RunPolicy
{
    public function view(User $user, Run $run): bool
    {
        return $run->subject !== null && $user->can('view', $run->subject);
    }

    public function cancel(User $user, Run $run): bool
    {
        return $run->subject !== null && $user->can('update', $run->subject);
    }

    public function retry(User $user, Run $run): bool
    {
        return $this->cancel($user, $run);
    }
}
```

```php
// app/Providers/AppServiceProvider.php, boot()
use App\Policies\RunPolicy;
use Illuminate\Support\Facades\Gate;
use Tey\Docket\Run;

Gate::policy(Run::class, RunPolicy::class);
```

### What a Ticket Contains

A ticket holds only the run's id, its read token, name, status, revision, poll interval and links, in every form: JSON, a page prop, a Livewire property or a queued payload. Rendered as HTML, it shows the snapshot's progress and counts, and embeds only its poll link. It includes the `cancel` and `retry` links only when the current user is allowed to use them. It **never** includes writer grants, capability secrets, the checkpoint, item content, any attribute of the subject, or the in-process `result()` of an inline run. The token locates a run; every read still goes through your policy, so revoking a user's access takes effect on their next poll.

### Capability Mode

Capability mode is for accountless callers, such as a keyless API, where there is no user for a policy to check. It is off by default and you turn it on for each run. A capability is a separate random secret with its own permissions (read, cancel, retry) and expiry. It is sent in the `Authorization` header, never in a URL. A run uses one mode: Docket never falls back to a capability when a policy check fails.

### Writer Grants

An external worker, such as a Python page processor, reports through a writer grant: a signed, expiring credential scoped to one run and one attempt. A writer grant is separate from every read token and capability, and it can't change a run's owner, access mode or expiry. Updates are signed requests that Docket checks for scope, replay and order.

## Retention

A run and its items are deleted when they expire: `docket.ttl` after the run finishes, or at the time you set with `expiresAt()`. An unfinished run is never deleted; `staleAfter()` takes care of runs that stop reporting.

`docket:prune` deletes expired runs and fails stale ones. Schedule it every minute:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('docket:prune')->everyMinute();
```

Docket stores references, never content: ids, paths and codes your app resolves. Deleting the files those references point to is your app's job: listen for `RunExpiring`, which `docket:prune` fires before it deletes a run.

## Testing

Fake dispatching to assert what was started:

```php
// tests/Feature/UploadTest.php
use App\Jobs\ImportRows;
use Tey\Docket\Facades\Docket;
use Tey\Docket\PendingRun;

it('imports an upload', function () {
    Docket::fake();

    $this->actingAs($user = User::factory()->create())
        ->post(route('uploads.store'), ['file' => UploadedFile::fake()->create('rows.csv')])
        ->assertRedirect();

    Docket::assertDispatched(ImportRows::class, fn (PendingRun $run) => $run->subject->user->is($user));
});
```

```php
Docket::assertBatched(fn (PendingRun $run) => $run->name === 'units.build');
```

Under the fake, `Docket::run()` still runs its closure, because your code needs the result. It passes a fake run and records it, so you can assert on inline runs too:

```php
Docket::assertRan('profile.update', fn (FakeRun $run) => $run->subject->is($profile));
```

To test what a job reports, run it with a fake run attached:

```php
// tests/Unit/ImportRowsTest.php
it('fails invalid rows', function () {
    [$job, $run] = (new ImportRows(Upload::factory()->withRows(['valid', 'invalid'])->create()))->withFakeDocket();

    $job->handle();

    $run->assertItemSucceeded('row:1');
    $run->assertItemFailed('row:2', 'row.invalid');
});
```

The fake records what the job reported. It doesn't prove queueing, final status, authorization or pruning; cover those with a feature test on a real queue connection.

## Before Production

| Check | Why |
| --- | --- |
| Schedule `docket:prune` every minute, on a host that runs even when queue workers scale to zero | Expired runs are deleted and stale runs are failed only by this command. |
| Use a background queue driver, not `sync` | With `sync`, `dispatch()` runs the work before it returns, so there is no early response. |
| Set `docket.ttl` for your data | Runs, items and logs stay until they expire. |
| Register a `Run` policy | The default policy hides scheduled runs and anything not started by the viewer. |
| Keep side effects safe to repeat | `retry()` and queue retries run work again. |
| Set `staleAfter()` on jobs without a `$timeout` | Otherwise a dead worker leaves a run `running` until `docket.stale_after`. |
| Allow for Docket's database connection | By default, each process that runs tracked work opens one more connection to your database (`docket.connection`). |

## Configuration

```bash
php artisan vendor:publish --tag=docket-config
```

| Key | Default | Meaning |
| --- | --- | --- |
| `ttl` | `86400` | Seconds a finished run is kept when it has no `expiresAt()`. |
| `stale_after` | `3600` | Seconds without a report before a run fails as `run.stalled`, when the job has no `$timeout`. |
| `poll_after_ms` | `2000` | The poll interval suggested to clients. |
| `routes.enabled` | `true` | Register the package routes. |
| `routes.prefix` | `docket` | Their URL prefix. |
| `routes.middleware` | `['web']` | Their middleware, for example `['api', 'auth:sanctum']`. |
| `connection` | `null` | The database connection Docket writes runs on. `null` opens a separate connection to your default database, so records survive your transactions' rollbacks. |
| `max_depth` | `10` | How deep runs may nest; a deeper `Docket::run()` or nested dispatch throws `run.depth_exceeded`. `null` turns the cap off. Use `->linkedTo()` to start fresh. |

## FAQ

### How Is This Different From Job Batches?

A batch counts jobs. Docket counts the items inside them (rows, pages, records) and keeps their outcomes, so you can show "3 rows failed" and retry only those. Docket runs on top of `Bus`: batches, chains, retries, backoff and `release()` behave as they always do.

### Is Docket Only for Queued Jobs?

No. The queue is one source. `Docket::run()` tracks code in a request or a command, with no queue at all, and gives it the same run, ticket and status.

### How Is This Different From an Activity Log?

An activity log records changes to models. Docket records a piece of work: what ran, who started it, how it ended, and its progress and item outcomes along the way. The two fit together: an observer can log to `Docket::current()`, so a model change is recorded on the run that caused it.

### How Is This Different From Horizon?

Horizon shows operators how queues and workers are doing. Docket shows your users how their work is going. They work well together.

### Do I Need Inertia?

No. Docket's routes return JSON, and the Blade component, Livewire and console work without it.

## Documentation

- [API reference](docs/reference.md)
- [JSON status contract](docs/json.md)
- [llms.txt](llms.txt) for coding agents

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See the [contributing guide](https://github.com/teylabs/.github/blob/main/CONTRIBUTING.md).

## Security Vulnerabilities

See the [security policy](https://github.com/teylabs/docket/security/policy).

## Credits

- [Jasper Tey](https://github.com/jaspertey)
- [All contributors](https://github.com/teylabs/docket/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
