# API Reference

> Planned API for Docket. Each entry is tagged with the milestone it lands in; nothing is released yet.

Namespace `Tey\Docket` unless noted. **0.x** = the milestone that ships it (see Part 5).

Implemented for 0.1, unreleased (#31):

- [x] Partial route configuration preserves unspecified defaults; middleware lists replace rather than merge, including an empty list.
- [x] Disabled routes leave ticket and snapshot `links` empty and omit ticket response `Location`, without requiring a prefix or middleware. Other fields and HTTP statuses are unchanged.
- [x] SQLite write-lock limits, after-commit database queue integration and application cleanup through `catch()` are documented below.

Implemented for 0.1, unreleased ([#29](https://github.com/teylabs/docket/issues/29)):

- [x] `Docket::find(string $token): ?Run` reads stored runs by token without checking access. Missing tokens return `null`; ids and names are not lookup keys. Expiry is enforced by package routes and pruning, not this model lookup.
- [x] Installation lists all five tables, the fake upload example asserts the 0.1 JSON response, and the SQLite `:memory:` testing recipe uses an explicit shared connection.

Implemented for 0.1, unreleased ([#13](https://github.com/teylabs/docket/issues/13)):

- [x] Stack unwinding on return, exceptions and `fail()`, queue exit resets, and Octane request, task and tick boundary resets.
- [x] One database-queue worker processes 100 warm-up jobs and 1,000 measured jobs, with an empty stack between jobs and released run objects. Live PHP memory samples every 100 jobs must stay within 1 MiB after warm-up.
- [x] Architecture coverage rejects static properties declared by Docket classes or traits; inherited Laravel model and facade caches are outside this rule.
- [x] Progress query bounds at depths 1, 5 and 10; one ancestor fingerprint query regardless of job count or depth, and one latest-log query for a tree.
- [x] Exact-repeat coverage includes inline and queued recursion, continuations, normalized inputs, repeat opt-outs, release/retry attempts and fresh retry/linked histories.
- [x] Ticket serialization loads its run once per ticket instance. `run()` still loads current storage, and every serialization checks the current viewer's permissions again. The cached model and inline result never cross PHP serialization.

Implemented for 0.1, unreleased ([#4](https://github.com/teylabs/docket/issues/4)):

- [x] Publishable migrations for `docket_runs`, `docket_items` and `docket_logs`; `docket.connection`.
- [x] `Run` attributes, enum casts, relations, `results()`, status checks, `cancelRequested()` and query scopes.
- [x] `Run::toArray()` as the v1 status snapshot, with persisted progress and child counters.
- [x] `RunStatus`, `Item`, `ItemStatus`, `Log` and `HasRuns` (`runs()` and `latestRun()`).
- [x] `Progress` (`done`, `total`, `percent` and its string form).
- [x] Reporting methods (#7).
- [x] Cancel and retry control methods (#10).
- [x] Tree traversal and automatic progress/status roll-up (#8).
- [x] Package JSON routes and the initiator-only default policy (#12).

Implemented for 0.1, unreleased ([#5](https://github.com/teylabs/docket/issues/5)):

- [x] `Docket::job()`, `batch()`, `chain()`, `dispatch()` and `dispatchSync()`; independent option starters.
- [x] `PendingRun::name()`, `about()`, `by()`, `unique()`, `idempotencyKey()`, `total()`, `staleAfter()`, `expiresAt()`, `then()`, `catch()` and `finally()`.
- [x] Default names, authenticated initiators, keyed batch totals, native Laravel jobs, batches and chains, and enqueueing after commit with `run.rolled_back` on rollback.
- [x] `PendingRun::dispatch()` and `dispatchSync()`, final status, expiry timestamps and completion callbacks. Item settlement and added work are implemented in #7; staleness reaping in #10.
- [x] `Run::ticket()` and `Ticket`'s readonly fields, `run()`, `toArray()`, `jsonSerialize()` and JSON `toResponse()`; PHP serialization carries the ticket fields only.
- [x] `RunDispatched` and `RunFinished`, after Docket's commit, with stable event ids.
- [ ] Browser redirects and rendered progress surfaces (0.2).

Implemented for 0.1, unreleased ([#6](https://github.com/teylabs/docket/issues/6)):

- [x] `Docket::run()` and `PendingRun::run()`, named or unnamed, with the existing 0.1 options and completion callbacks.
- [x] `Ticket::result()` holds the closure's return value only on that ticket, including `null`; JSON and PHP serialization omit it. Queued, rehydrated, stored and deduplicated tickets throw `ResultNotAvailable`.
- [x] Exceptions record `run.exception` and log only the class, then rethrow unchanged. Inline `Run::fail()` throws `RunFailed` with the supplied code and params.
- [x] Inline work executes immediately inside an application transaction; successful completion waits for commit, and rollback records `run.rolled_back` on Docket's connection.
- [x] `Docket::current()` uses a scoped stack that unwinds in `finally`, with queue exit resets and Octane request, task and tick boundary resets. Synchronous queue work restores its caller's stack.
- [x] Parent/child persistence and roll-up (#8).
- [x] Depth and loop protection (#9).
- [ ] Progress bars (0.2).

Implemented for 0.1, unreleased ([#7](https://github.com/teylabs/docket/issues/7)):

- [x] `Docketable::docket()`, with the same object as `Docket::current()` during handling, and `null` on untracked jobs.
- [x] `track()`, eager declarations and totals, lazy declarations, succeeded-key skipping (including `retry_of_run_id`), implicit success and unsettled items on `break` or exceptions.
- [x] Item outcomes, weighted success counts, reference results and stable, after-commit `ItemFinished` events. The first outcome for a key wins; repeated reports never count twice.
- [x] Keyed jobs and batch chains (keyed by their first job); final keyed failures record `run.job_failed` on the item, while unkeyed failures end the run as `run.failed`.
- [x] `total()`, `step()`, `log()`, `result()`, `checkpoint()`, `add()`, queued `fail()` and `throwIfCancelled()`.
- [x] Added work keeps its run open, queues after commit, and runs synchronously under `dispatchSync()`. Internal `docket_work` records make completion safe to repeat without storing jobs in memory.
- [x] Structural limits: params have up to 20 string-keyed scalar/null entries and at most 4,096 encoded JSON bytes. Checkpoints are scalar/null or a flat array of scalar/null values, at most 4,096 encoded JSON bytes. Invalid values throw `InvalidArgumentException` before writing. The snapshot includes at most 20 newest item results.
- [x] Loop protection for added work (#9).
- [x] Creation of retries and checkpoint inheritance (#10).
- [x] `withFakeDocket()` (#11).

Implemented for 0.1, unreleased ([#8](https://github.com/teylabs/docket/issues/8)):

- [x] Nested inline runs and Docket dispatches persist their parent and root, with independent tickets. `parent()`, `root()`, `children()` and breadth-first `descendants()` read the tree.
- [x] `tree($viewer)` returns the documented node fields, policy-filtered, with the latest log and no item content. An inaccessible root returns `[]`; an inaccessible child and its branch are omitted. Traversal respects `docket.max_depth` (default 10; `null` is unlimited).
- [x] Progress sums the run's own units and attached children's progress. An unknown-total child contributes 0 of 1 until finished, then 1 of 1. Direct-child counts include detached children; item counts remain local to each run.
- [x] Ancestor counters use atomic increments in one O(depth) walk per progress update, without querying siblings or holding locks while child work executes.
- [x] Parents wait for attached children before completion callbacks and `RunFinished`. Caught or queued child failures make the parent partial; an uncaught child exception is rethrown unchanged and records `run.child_failed` with the direct child's id. A pending failure is preserved while other children finish.
- [x] `PendingRun::detached()` keeps the child listed but excludes its progress and status from the parent's roll-up. Cancelled children count only in `children.total` and do not make a parent partial.
- [x] Depth enforcement, linked top-level runs and loop rescue (#9).
- [x] Children HTTP pages and the default policy (#12).
- [ ] Tree UI and console watchers (0.2).

Implemented for 0.1, unreleased ([#9](https://github.com/teylabs/docket/issues/9)):

- [x] `docket.max_depth` defaults to 10; roots are depth 0 and `null` removes the cap. A rejected child is recorded as `run.depth_exceeded` with `params.max_depth`, without executing or queueing its work.
- [x] `linkedTo(Run|Ticket|null)` starts a separate root, with `linked_from_run_id`, `linked()` and `linkedFrom()`. Linked work never contributes to the original's progress, status or completion; `null` is a no-op.
- [x] Durable fingerprints detect exact ancestor recursion and repeated additions, including repeats within one `add()` call. Job keys take precedence; otherwise fingerprints use the class and normalized serialized input. Inline fingerprints use the name and closure file/line.
- [x] `allowRepeat()` and `add($jobs, allowRepeat: true)` allow deliberate repeats. Succeeded item keys are still skipped. Queue attempts and releases do not register new work; retry lineage does not share fingerprint history.
- [x] `run.loop_detected` records `params.job` and `params.repeats` (the ancestor run id or first work id). `Events\LoopDetected` supplies the failed branch, the repeated run and the fingerprint, with a stable event id; the exception is reported to Laravel.
- [ ] Forward snapshot links to linked runs: the accepted JSON contract and route table do not define a field or route for them. Use `linked()` and each linked run's ticket; no undocumented JSON field or route is added.

Implemented for 0.1, unreleased ([#10](https://github.com/teylabs/docket/issues/10)):

- [x] `cancel()` requests cancellation once, cancels the Laravel batch and does nothing on finished runs. Safe points preserve completed items and clear `cancel_requested` on completion.
- [x] `retry()` returns a new top-level pending run with `retry_of_run_id`, fresh identity and fingerprint history, inherited checkpoint and copied successful items. Copies survive pruning the original and further retries. Partial, failed and cancelled runs may be retried; other statuses throw `LogicException`.
- [x] Replay retains the original job, batch, chain or closure input, encrypted with Laravel's application key on Docket's connection, never in a ticket or snapshot. Inputs must follow the same reference-only discipline as queued jobs. Inline closures replay as queued closures; `dispatchSync()` is available. Work PHP cannot serialize still executes inline, but `retry()` throws `LogicException` when no replayable work was retained.
- [x] Retries retain name, subject, initiator, total, stale interval and completion callbacks. They reset cancellation, errors, results, unique/idempotency keys, tree membership and expiry; the new run uses the normal TTL unless `expiresAt()` is set again. Added work is regenerated by replaying the original work, not dispatched a second time from a saved continuation list.
- [x] `docket:prune` fails queued/running work strictly after its last reporting call plus `staleAfter`, using stored second-resolution timestamps. Control changes do not renew the deadline. Attached children are reaped before waiting parents, preserving the normal partial/failure roll-up instead of declaring a waiting parent stalled.
- [x] Expired finished runs are deleted with their items, logs and internal work records. `RunExpiring` has a stable id and runs before deletion, outside a transaction; synchronous listeners finish first. A listener exception leaves the run for the next invocation. Cleanup that must finish before deletion must use a synchronous listener, not a queued listener.

Depth and recursion checks also apply to batch and chain runs. Each initial job is remembered, including every chain member; initial sibling jobs are not continuations. A repeated addition rejects the whole call before queueing any of it. A continuation loop stops accepting work immediately; like other failures, final completion waits for already attached children. Fingerprint history is stored on Docket's connection and is deleted with its run; job input is never stored in that history or exposed in snapshots.

`track()` skips succeeded keys, as specified in the README, not every terminal outcome. Arrays and eager collections declare immediately even when the returned generator is not iterated. Lazy inputs declare only as reached and do not infer a total. An explicit `total()` wins over inferred totals. The optional key in `itemFailed()` and `itemSkipped()` requires a PHP default for the following code parameter; omitting the code is rejected by validation. Success codes such as `created` are accepted, along with dotted codes; `run.` is reserved. Item result references accept strings or integer ids and are stored as strings.

Cancellation stops at the next safe point, preserving completed items. Inline execution returns a cancelled ticket; queued execution stops without a retry. Reporting does not change a finished run. Synchronous keyed exceptions are reported to Laravel's exception handler and settle only their item, so other batch members can finish.

An explicit name passed to `run()` takes precedence over `name()`; the closure-only form keeps `name()` or defaults to `closure`. A reused unique or idempotent run does not execute the closure again and cannot recover an earlier in-process result. An exception failure is final and is not replaced by a later rollback.

With `connection => null`, Docket opens a separate connection using the default database's configuration. SQLite `:memory:` databases belong to one connection, so use file-backed SQLite when application and Docket records need to share a database through independent connections. For tests that share one connection, see the recipe below. SQLite also permits only one writer at a time; use a separate Docket database if the application must hold a write transaction while Docket writes, including native synchronous batches that report items or logs.

### Testing With SQLite In Memory

After publishing `docket-config`, make its connection configurable in your application's `config/docket.php`:

```php
// config/docket.php, inside the returned array
'connection' => env('DOCKET_DB_CONNECTION'),
```

Set both the application and Docket to the same named connection before migrations run. Add these entries to the `<php>` section of your application's `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="DOCKET_DB_CONNECTION" value="sqlite"/>
```

Use Laravel's `RefreshDatabase` in those tests and publish Docket's migrations as shown in the README. Both now use the same in-memory database. Setting this only in a test's `beforeEach` is too late when `RefreshDatabase` has already migrated.

This is a testing configuration: sharing a connection also shares transactions, so Docket records do not survive an application rollback. Test rollback durability with independent connections instead. Leave `DOCKET_DB_CONNECTION` unset outside these tests to keep the default separate connection.

### Transaction-Coupled Database Queues

Commit application writes before dispatching when the database queue shares the application's database. For example, in a controller after validating and storing an upload:

```php
// app/Http/Controllers/UploadController.php
use App\Jobs\ImportRows;
use App\Models\Upload;
use Illuminate\Support\Facades\DB;
use Tey\Docket\Facades\Docket;
use Tey\Docket\Run;

// Inside store(), after storing the file as $path:
// There must be no outer application transaction around this code.
$upload = DB::transaction(fn () => Upload::create([
    'path' => $path,
    'status' => 'queued',
]));

try {
    return Docket::job(new ImportRows($upload))
        ->about($upload)
        ->catch(static function (Run $run): void {
            $run->subject?->update(['status' => 'failed']);
        })
        ->dispatch();
} catch (\Throwable $exception) {
    // Also cover failures before a run and its callbacks have been saved.
    $upload->update(['status' => 'failed']);

    throw $exception;
}
```

The upload is committed before Docket saves its run or writes to the queue. A dispatch error cannot roll back that upload, so the outer `catch` marks it failed. The run's `catch()` handles later failures, including failure to enqueue a continuation through `add()`. Keep cleanup idempotent: both paths may run for an initial enqueue failure. If dispatch must be registered inside an outer transaction, put the entire Docket dispatch and its error cleanup in `DB::afterCommit()` instead; no ticket is available until that callback executes.

With SQLite, the default separate Docket connection still writes to the same database file. An open application write transaction blocks it: starting a run or reporting progress, items or logs can deadlock or end with `database is locked`. Laravel's queue `after_commit` option only delays queue insertion; it does not delay Docket's run or reporting writes. In a job, finish the application write transaction before calling reporting methods or `add()`. If reporting must happen inside it, configure `docket.connection` to a separate database with Docket's migrations. Sharing the application's connection avoids the second writer but also rolls back Docket's records, so it is not a durable alternative.

When `add()` enqueueing fails, Docket records `run.failed` and stops later deliveries from executing. A skipped delivery does not call the job's `failed()` hook. Synchronize application status and release application resources in the run's `catch()` callback, not solely in job failure hooks. As with other failures, final callbacks wait for already attached children to finish.

### Facade `Tey\Docket\Facades\Docket`

| Signature | Meaning | Ms |
| --- | --- | --- |
| `run(string\|Closure $name, ?Closure $callback = null): Ticket` | Run the closure now, in this process, as a run (inside an active run, as a child of it, up to `docket.max_depth` levels). The closure receives `Run $run`. Returns the run's ticket, with the closure's return value on `result()` . Any exception fails the run as `run.exception` and is rethrown. Without a name, the run is named `closure`. | 0.1 |
| `current(): ?Run` | The innermost run whose work is executing now: inside a `run()` closure or a `Docketable` job's `handle()`. `null` otherwise. A stack: a child pops when it finishes, even on exception; reset between requests and jobs (Octane-safe). | 0.1 |
| `about()`, `by()`, `name()`, `total()`, `expiresAt()`, `then()`, `catch()`, `finally()`, `withProgressBar()`, … | Each starts a new `PendingRun` with that option set, so an inline run can take options: `Docket::about($model)->run(...)`. | 0.1 |
| `job(object\|Closure $job): PendingRun` | A pending run for one queued job or closure. A closure receives `Run $run`. | 0.1 |
| `batch(iterable $jobs): PendingRun` | A pending run for a Laravel batch. A member can be a job or an array (a chain). | 0.1 |
| `chain(array $jobs): PendingRun` | A pending run for a Laravel chain. | 0.1 |
| `dispatch(object $job): Ticket` | Shortcut for `job($job)->dispatch()`. | 0.1 |
| `dispatchSync(object $job): Ticket` | Shortcut for `job($job)->dispatchSync()`. | 0.1 |
| `find(string $token): ?Run` | Find a run by its token. It doesn't check access. | 0.1 |
| `fake(): DocketFake` | Swap in the fake (see below). | 0.1 |

### `PendingRun` (built by the facade or `Run::retry()`)

| Signature | Meaning | Ms |
| --- | --- | --- |
| `about(Model $subject): static` | Set the subject (morph). | 0.1 |
| `by(?Authenticatable $user): static` | Set the initiator. Defaults to `auth()->user()`. | 0.1 |
| `name(string $name): static` | Set a stable name. Defaults to the job class, or `closure`. | 0.1 |
| `unique(): static` | Return the existing unfinished run with the same name and subject. | 0.1 |
| `idempotencyKey(?string $key): static` | Return the same run for a repeated name and key until it expires. `null` is ignored. | 0.1 |
| `total(int $units): static` | Set the expected units. | 0.1 |
| `staleAfter(int $seconds): static` | Fail as `run.stalled` after this long without a report. | 0.1 |
| `expiresAt(DateTimeInterface $time): static` | Set when the run is deleted. | 0.1 |
| `then(Closure $callback): static` | Called with `Run` when the run succeeds. | 0.1 |
| `catch(Closure $callback): static` | Called with `Run` when the run ends as failed. | 0.1 |
| `finally(Closure $callback): static` | Called with `Run` on any final status. | 0.1 |
| `withProgressBar(OutputStyle $output): static` | Draw a progress bar and summary during `run()` or `dispatchSync()`. | 0.2 |
| `dispatch(): Ticket` | Save the run, queue the work (after commit inside a transaction) and return a ticket. | 0.1 |
| `dispatchSync(): Ticket` | Run the pending job in this process, including added jobs. The ticket's status is the status when it returns. | 0.1 |
| `run(string\|Closure $name, ?Closure $callback = null): Ticket` | Run a closure inline with this pending run's options (see the facade's `run()`). A pending run built from `job()`, `batch()` or `chain()` can't `run()`; use `dispatchSync()`. | 0.1 |
| `detached(): static` | Inside a run, dispatch a linked child the parent doesn't wait for and doesn't roll up. | 0.1 |
| `linkedTo(Run\|Ticket\|null $run): static` | Start a new top-level run (depth 0) linked back to `$run`, the escape hatch from `docket.max_depth`. It never holds `$run` open or counts in its roll-up. `null` is a no-op, so `->linkedTo(Docket::current())` is safe outside a run. Works on `run()`, `job()`, `batch()`, `chain()` and `dispatch()`. | 0.1 |
| `allowRepeat(): static` | Allow this dispatch's work to repeat an ancestor's exact fingerprint without failing as `run.loop_detected`. Use it only for deliberate identical passes. | 0.1 |
| `withCapability(array $permissions): static` | Use capability mode for this run. | 0.3 |
| Readable properties: `name`, `subject`, `initiator`, `jobs` | For fake assertions. | 0.1 |

### `Ticket` (value object; `JsonSerializable`, `Arrayable`, `Responsable`, `Htmlable`, `Wireable`; not `Stringable`, as ruled)

| Signature | Meaning | Ms |
| --- | --- | --- |
| `readonly string $id` | The run id (ULID). | 0.1 |
| `readonly string $token` | The read token. | 0.1 |
| `readonly string $name` | The run name. | 0.1 |
| `readonly RunStatus $status` | The status when the ticket was made. | 0.1 |
| `readonly int $revision` | The run revision when the ticket was made. | 0.1 |
| `run(): Run` | Load the run. | 0.1 |
| `result(): mixed` | The closure's return value, from a ticket returned by `Docket::run()`. In-process only: held on this object, never serialized or stored. On a queued dispatch's ticket it throws `Tey\Docket\Exceptions\ResultNotAvailable` ("queued work finishes later; read `$ticket->run()->result` once it's finished") . | 0.1 |
| `toArray(): array` / `jsonSerialize(): array` | The ticket JSON (Part 3). Links are resolved against the current user. | 0.1 |
| `toResponse($request): Response` | When the request expects JSON: `202` when unfinished, `200` when finished, with a `Location` header set to the poll link. Otherwise a `303` redirect to the watch page (in 0.1, always JSON). | 0.1 JSON, 0.2 redirect |
| `url(): string` | The watch page URL, or the poll link when the watch page is off. | 0.2 |
| `toHtml(): string` | Loads the run and renders `<x-docket-progress>` with its current snapshot. | 0.2 |
| `toLivewire(): array` / `static fromLivewire($value): static` | Livewire round trip: the ticket fields only; rehydrates by id. | 0.2 |

The ticket is the hand-out side; `Run` is the read and report side. An inline run has no dispatch response, so its ticket comes from `$run->ticket()`. `Run::ticket()` and `Ticket::run()` convert between them. A ticket never loads or serializes the subject, checkpoint, items, writer grants, capability secrets or its in-process `result()`.

### `Run` (Eloquent model)

Reading:

| Signature | Meaning | Ms |
| --- | --- | --- |
| `$id`, `$token`, `$name`, `$step`, `$total`, `$revision`, `$result` | Attributes. | 0.1 |
| `$status: RunStatus` | Enum cast. | 0.1 |
| `$cancel_requested`, `$finished_at`, `$expires_at`, `$created_at` | Attributes. | 0.1 |
| `subject(): MorphTo`, `initiator(): MorphTo`, `parent(): BelongsTo`, `root(): BelongsTo`, `retryOf(): BelongsTo`, `linkedFrom(): BelongsTo`, `linked(): HasMany` | Relations. `linked()` lists runs started with `->linkedTo($this)`; `linkedFrom()` is the run this one was linked from. `root()` is the top of the tree (the run itself when it has no parent). | 0.1 |
| `children(): HasMany` | Child runs: nested `Docket::run()` calls and `Docket::job()` dispatches inside this run. | 0.1 |
| `items(): HasMany` | `Item` models. | 0.1 |
| `logs(): HasMany` | `Log` models (code, params, created_at). | 0.1 |
| `results(): Collection` | Item results keyed by item key, in finish order. | 0.1 |
| `$progress: Progress` | A value object with `done`, `total` and `percent`, rolled up from children. It prints as `620 of 1,000 (62%)`, or `620 done` without a total. | 0.1 |
| `descendants(): Collection` | Every run below this one, breadth first, down to `docket.max_depth`. | 0.1 |
| `tree(?Authenticatable $viewer = null): array` | This run and its descendants as nested arrays: each node is the snapshot's `id`, `token`, `name`, `status`, `step`, `progress`, `counts` and `latest_log`, plus `children: [...]`. Only runs `$viewer` may `view` are included (defaults to the signed-in user). Never item content. | 0.1 |
| `finished()`, `succeeded()`, `partial()`, `failed()`, `cancelled(): bool` | Status checks. | 0.1 |
| `cancelRequested(): bool` | Whether a cancel is pending. | 0.1 |
| `ticket(): Ticket` | A ticket for this run. | 0.1 |
| `toArray(): array` | The status snapshot (Part 3), never the columns. | 0.1 |
| Scopes: `named($name)`, `unfinished()`, `finished()`, `succeeded()` | Query helpers. | 0.1 |

Control:

| Signature | Meaning | Ms |
| --- | --- | --- |
| `cancel(): static` | Request cooperative cancellation; safe to repeat; also cancels the Laravel batch. A no-op on a finished run. | 0.1 |
| `retry(): PendingRun` | A pending linked run: same work, succeeded items skipped, checkpoint inherited. Throws on an unfinished or succeeded run. | 0.1 |

Reporting (inside the work; returns `static` unless noted):

| Signature | Meaning | Ms |
| --- | --- | --- |
| `step(string $label)` | Set the current step. | 0.1 |
| `total(int $units)` | Set or correct the expected units. | 0.1 |
| `track(iterable $items, ?Closure $key = null): Generator` | Declare items and yield `key => item`. It skips succeeded keys, checks cancellation and records implicit success. `$key` receives `($item, $index)`. Without `$key`, the items are the keys. | 0.1 |
| `itemSucceeded(?string $key = null, mixed $result = null, ?string $code = null, int $count = 1)` | Record success. `$key` may be omitted in a keyed job. | 0.1 |
| `itemFailed(?string $key = null, string $code, array $params = [])` | Record failure. | 0.1 |
| `itemSkipped(?string $key = null, string $code, array $params = [])` | Record a skip. | 0.1 |
| `log(string $code, array $params = [])` | Add an activity log entry. Params must be scalars. | 0.1 |
| `result(string $reference)` | Set the run-level output reference. | 0.1 |
| `checkpoint(mixed $position): static` / `checkpoint(): mixed` | Write or read the resumable position. | 0.1 |
| `add(object\|iterable $jobs, bool $allowRepeat = false)` | Queue more jobs into this run; succeeded keys are skipped. An inline run with added jobs finishes when they do. Exact repeats fail the run unless `allowRepeat: true`. | 0.1 |
| `fail(string $code, array $params = []): void` | End the run as failed with a user-safe code. In a queued job, the job isn't retried. Inside `Docket::run()`, it throws `RunFailed`, which `run()` records and rethrows. | 0.1 |
| `throwIfCancelled(): void` | Stop at a safe point if cancellation was requested. | 0.1 |
| `writerGrant(): WriterGrant` | A signed, expiring grant for an external worker. | 0.3 |

### `RunStatus` (enum, string-backed)

`Queued`, `Running`, `Succeeded`, `Partial`, `Failed`, `Cancelled`; `isFinished(): bool`. 0.1.

### `Item` (Eloquent model)

`$key`, `$status: ItemStatus` (`Pending`, `Succeeded`, `Failed`, `Skipped`), `$code`, `$params`, `$result`, `$count`, `$finished_at`. Scopes: `pending()`, `succeeded()`, `failed()`, `skipped()`. 0.1.

### `HasRuns` (model trait)

| Signature | Meaning | Ms |
| --- | --- | --- |
| `runs(): MorphMany` | Runs about this model. | 0.1 |
| `latestRun(): MorphOne` | The newest run (`latestOfMany`). | 0.1 |

### `Docketable` (job trait)

| Signature | Meaning | Ms |
| --- | --- | --- |
| `docket(): ?Run` | The run this job belongs to, or `null` when not dispatched through Docket. While `handle()` runs, `Docket::current()` returns the same run. | 0.1 |
| `docketKey(): string` | Optional; you define it. It makes the job an item in its run. | 0.1 |
| `withFakeDocket(): array` | Returns `[$job, FakeRun $run]` for unit tests. | 0.1 |

### Events (`Tey\Docket\Events`)

Each has `string $id` (stable, for deduplication) and `Run $run`. Delivery is at least once, after commit. 0.1.

| Event | Extra | When |
| --- | --- | --- |
| `RunDispatched` | none | the run is saved and its work queued, or started inline |
| `ItemFinished` | `Item $item` | an item reaches an outcome |
| `RunFinished` | none | the run reaches a final status |
| `RunExpiring` | none | `docket:prune` is about to delete the run; deletion waits for listeners |
| `LoopDetected` | `Run $repeats`, `string $fingerprint` | a continuation or child repeated earlier work exactly; the branch failed with `run.loop_detected`, and the exception is reported |

### Commands

| Command | Meaning | Ms |
| --- | --- | --- |
| `docket:prune` | Delete expired runs (items and logs cascade); fail stale runs as `run.stalled`. | 0.1 |
| `docket:watch {run} {--depth=1}` | Follow a run by token, or the newest run with that name, until it finishes. Prints the root's roll-up, then one line per child (to `--depth` levels) with status, progress and latest log entry. | 0.2 |

### Routes

The table in the README. Package JSON routes: 0.1. The watch page: 0.2. The external update route `POST /docket/runs/{id}/updates` (writer grants): 0.3.

Implemented for 0.1, unreleased (#12):

- [x] Snapshot, items, logs, children, cancel and retry routes, with configurable `routes.enabled`, `routes.prefix` and `routes.middleware` (defaults: `true`, `docket`, `['web']`).
- [x] The default policy allows only the initiator, matching both morph type and id. An application policy registered with `Gate::policy()` takes precedence. Guests and runs without an initiator are denied by default.
- [x] Every endpoint checks `view` before reading or acting; unknown, expired and unviewable runs return the same 404. Control permission failures return 403; state conflicts and work without retained replay input return 409.
- [x] Cursor pages contain at most 100 entries. Children are policy-filtered before public cursor boundaries are chosen; expired children are omitted. Items and logs use ascending ids; children use creation time, then id. Invalid status filters or cursors return Laravel's 422 validation response.
- [x] All package responses use JSON and `Cache-Control: private, no-store`. Snapshot polling is limited per viewer and run to one request per `poll_after_ms`, rounded up to whole seconds (at least one); guests allowed by an application policy are keyed by IP. Faster polls return 429 with `Retry-After`. Items, logs and children can be fetched alongside a snapshot.

### JavaScript (Vue and React share names)

| Export | Meaning | Ms |
| --- | --- | --- |
| `useDocketPoll(prop: string, options?: { interval?: number, reloadOnFinish?: string[] })` | Returns the live snapshot (a Vue ref, or a React value) for a page prop that holds a ticket or a snapshot. It polls `links.poll`, stops at a final status and follows a changed prop. `reloadOnFinish` makes Inertia reload those props once the run finishes. | 0.2 |
| `<DocketProgress :run>` | Renders a snapshot or ticket: step, bar (indeterminate without a total), counts, final state, `aria-live`. | 0.2 |
| `<DocketTree :run>` | The root's `<DocketProgress>` plus a live list of its children (status, progress, latest log code), read from `links.children`. Only children the viewer may view appear. | 0.2 |

### Blade

`<x-docket-progress :run="$run" />` renders a `Run`, a `Ticket` or `null`. `{{ $ticket }}` renders the same component through `Htmlable`. The watch page (`docket::watch` view, published with `docket-views`) uses it too. 0.2.

### Fake (`Docket::fake()` → `Tey\Docket\Testing\DocketFake`)

Implemented for 0.1, unreleased ([#11](https://github.com/teylabs/docket/issues/11)):

- [x] Dispatch, batch, chain and inline assertions, including counts, callbacks and negative assertions.
- [x] Inline closures execute with `Tey\Docket\Testing\FakeRun` as the current run; nested calls restore their parent even on exceptions. Results stay on the returned ticket only.
- [x] `FakeRun` reporting assertions and `Docketable::withFakeDocket()`, including a keyed job's default item key, without database writes or queueing.

The fake records dispatch requests, including `dispatchSync()`, without executing jobs. Inline runs and dispatches have separate assertion histories; `assertNothingDispatched()` does not reject inline runs. A new `fake()` starts a fresh history. Chain assertions compare ordered classes or job instances and their properties.

The fake does not simulate lifecycle completion, callbacks, deduplication, retries, pruning or tree roll-up. Inline fake tickets stay `running`; dispatch tickets are `queued`. Ticket JSON keeps the normal fields, without reports or results. `withFakeDocket()` attaches the fake to the job, not to the global current-run stack: calling a job's `handle()` directly has no execution boundary Docket can wrap. Use a faked `Docket::run()` when the code under test needs `Docket::current()`.

Reporting assertions match recorded values, not just the latest value. Optional item codes and log params narrow the match; omitted values match any code or params. Params and checkpoints use strict value matching. `assertAdded()` accepts a job class or a predicate receiving each added job. `track()` keeps the normal eager/lazy declaration, succeeded-key skipping, implicit success and unsettled-on-break behaviour. The first item outcome wins. `fail()` records the code and throws `RunFailed`, so code after failure does not execute; catch it in a direct job unit test before asserting the report.

| Signature | Meaning | Ms |
| --- | --- | --- |
| `assertDispatched(string $job, Closure\|int\|null $callbackOrTimes = null)` | A run for this job was dispatched; the callback receives `PendingRun`. | 0.1 |
| `assertNotDispatched(string $job, ?Closure $callback = null)` | None was dispatched. | 0.1 |
| `assertBatched(Closure $callback)` | A batch run matching the callback was dispatched. | 0.1 |
| `assertChained(array $jobs)` | A chain run with these jobs was dispatched (mirrors `Bus`). | 0.1 |
| `assertNothingDispatched()` | No run was dispatched (mirrors `Bus`). | 0.1 |
| `assertRan(string $name, ?Closure $callback = null)` | An inline run (top-level or child) with this name ran; the callback receives its `FakeRun`. | 0.1 |
| `assertNotRan(string $name)` | No inline run with this name ran. | 0.1 |

A faked `dispatch()` returns a `Ticket` with status `queued` and queues nothing. A faked `run()` still runs the closure, with a `FakeRun` that is also `Docket::current()`, and returns a ticket whose `result()` is the closure's return value.

`FakeRun` (from `withFakeDocket()` or a faked `run()`) exposes `name`, `subject` and `initiator`, and records and asserts: `assertStep($label)`, `assertTotal($units)`, `assertItemSucceeded($key, ?$code)`, `assertItemFailed($key, ?$code)`, `assertItemSkipped($key, ?$code)`, `assertLogged($code, ?$params)`, `assertResult($reference)`, `assertCheckpoint($position)`, `assertAdded(string|Closure $job)`, `assertFailed($code)`. 0.1.

### Optional Concierge integration (ships in tey/concierge)

`Concierge::docket()` returns the same `Docket` builder, plus a team- and member-aware default `Run` policy. It adds no new vocabulary. 0.3, tracked on the Concierge side.
