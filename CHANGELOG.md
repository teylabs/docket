# Changelog

## Unreleased

## [0.6.6] - 2026-11-04

### Security

- `idempotencyKey()` was keyed by run name and key only, so two users sending the same key got one run. The key is now per initiator, like start-or-reuse, unless the run is `shared()`. (#63)
- With no initiator (a guest, a command, a job, a webhook), every caller shared one start-or-reuse identity, so `unique()` reused runs across guests, and across tenants when there was no subject. An unattributed dispatch that isn't `shared()` now never reuses: it starts a fresh run, without throwing, for both `unique()`/`uniqueBy()` and `idempotencyKey()`. (#63)

### Upgrade note

- An idempotency key stored before 0.6.6 is still honoured, but only for the user who used it.
- Unattributed callers (commands, jobs, webhooks, guest requests) that relied on `unique()` or `idempotencyKey()` to de-duplicate must now add `->shared()`, with a subject and/or a `uniqueBy()` scope that fits. A shared run is handed to a signed-in initiator only if the `view` policy admits them. A system dispatch has no viewer, so it skips that check; viewers still meet the policy in `snapshot()` and the package routes. (#63)

## [0.6.5] - 2026-11-04

### Security

- Start-or-reuse (`unique()`, `uniqueBy()`) matched on name and subject only, so one user could be handed another user's active run, and with no subject the run name alone crossed users and tenants. `snapshot()` as a modal prop then carried that run's state to the wrong user. The match now includes the initiator, so each user gets their own run. `->shared()` opts in to reusing someone else's run, and that reuse throws `AuthorizationException` for a viewer the run's `view` policy doesn't admit. `$ticket->snapshot()` and `Docket::snapshot()` throw `AuthorizationException` when the signed-in user may not view the run. (#62)
- `$run->outputPath()` turned a name of `.` or `..` into the run's folder or its parent. Leading dots are now dropped, and an empty result becomes `output`. (#62)

### Upgrade note

- Reuse is per initiator by default. Work that several users should share (a report about a workspace, say) needs `->shared()` and a `view` policy, such as `SubjectRunPolicy`, that admits everyone it is reused for. A run started before the upgrade is still found by its name, subject and initiator, so an in-flight run keeps being reused by the user who started it. A shared run without `uniqueBy()` keeps the key earlier versions stored. A caller that hands `snapshot()` a run the user can't view now gets an exception instead of the state. (#62)

## [0.6.4] - 2026-11-04

### Fixed

- The `stepper` variant of `<DocketProgress>` no longer adds its own "Try again" button beside the host's actions. `retry` now defaults to off for `stepper` and on for `bar` and `minimal`, as before; pass `:retry="true"` to keep it. (#61)
- After a failure, the stepper lists only the steps up to the failed one and drops the actions step, instead of showing later steps as pending next to disabled success actions. (#61)
- A failed step no longer shows its raw error code. It shows `failures[code]` (a new prop, like `steps`), else the server's checklist reason, else a generic message. (#61)

### Added

- Run in a modal: the host's controller starts or reuses the run and hands the dialog its snapshot, in one request.
  - `->uniqueBy(...$values)` starts the run or returns the active one with the same name, subject and extra values (a format, a period). It implies `unique()`, and the unique database key means two racing starts never create two runs. (#61)
  - `$ticket->snapshot()` and `Docket::snapshot($runOrTicket)` return the full first snapshot for a page or modal prop (token, `links.poll`, `links.stream`, `steps`, `checklist`, `failure`), so the first paint needs no fetch. Snapshots gain `links.stream` and `failure` (`{ step, code, reason }`, once, for a failed run). (#61)
  - `<DocketRunDialog :run>` is the dialog body. It paints the snapshot, streams the run (falling back to polling), and has a `steps` slot (`items`, `enabled`, `finished`) for a checklist component and an `actions` slot (`download`, `retry`). A failed run shows only the failed step, one alert with the server's reason and Retry, with no download and no duplicated text. (#61)
  - `$run->outputPath($name, $directory)` gives each run its own storage path, `{directory}/{run id}/{name}`, so two builds never write over each other's file. (#61)

## [0.6.3] - 2026-11-04

### Fixed

- `failed_children` checked each child's `view` policy against the signed-in user, while the runs list checks its given viewer. `Run::failedChildren(?Authenticatable $viewer)` and `RunList::row(..., $viewer)` now take the viewer, so list rows and stream ticks check the same user as the rest of the row; snapshots still use the signed-in user, as their links do. (#60)

### Upgrade note

- `required` defaults to `false`, and the 0.6.2 migration gives existing rows `false`. Children dispatched before you upgrade, including ones still in flight at deploy, count as optional: if they fail, their parent finishes `partial` rather than failed. Only children started with `->required()` after the upgrade fail their parent. (#60)

## [0.6.2] - 2026-11-04

### Added

- The parent decides what a child failure means. `->required()` on a child run (`Docket::required()->run(...)`, `Docket::job($job)->required()->dispatch()`) makes its failure the parent's: the parent fails with the child's error code and params, plus `child`, the trail back to it, through any number of required levels. A child that isn't required (the default, unchanged) leaves the parent to finish `partial`. Publish and run the new migration (a `required` column). (#59)
- Snapshots and runs-list rows gain `failed_children`: the direct children that failed, each with its `label`, `required`, `code` and the `reason` to show (from `docket.runs.reasons`, else `docket.runs.copy.reason`), at most 20 and only those the viewer may view. The `checklist` adds each failed optional child as a `skipped`, `required: false` item with its reason, so a checklist reads "done with problems" rather than blocked; a required child's failure already shows on the failed step. `<DocketRunsList>` lists the failed children. (#59)

## [0.6.1] - 2026-11-04

### Security

- `$run->outputLink()` accepted protocol-relative (`//other.host/…`) and backslash (`/\other.host`) links, which browsers open on another host. It now accepts only an absolute `http(s)://` URL with a host and no user info, or a path on this host (`/` not followed by `/` or `\`), and rejects whitespace, control characters and backslashes. (#58)
- Event streams ran their checks after the request's middleware had restored its context, so a tenant or other ambient state set there was gone when the runs list's `scopeUsing()` scope and the `view` policy ran. `Docket::runs()->streamContextUsing(fn (Closure $next, Request $request) => …)` now wraps each tick of both the runs-list stream and a run's stream, so the host can re-enter its context. Scopes and custom policies should read the `$viewer` and filter they are given rather than ambient state. (#58)

### Fixed

- An unmapped failure no longer shows its raw error code to people: list rows and checklist items fall back to `docket.runs.copy.reason` ("Something went wrong."), and carry the code in a separate `code` field for logs and support. (#58)

## [0.6.0] - 2026-11-04

### Fixed

- A run no longer shows every step done before its result is there. The status was committed before `then()` callbacks and `RunFinished` listeners ran, and those often record the output, so a stepper could show all checks with Download and View still unavailable. A finished run is now `settling` until they finish (at most `docket.settle_seconds`, 30): the snapshot gains `settling`, keeps `poll_after_ms`, and keeps the last step `active`; streams stay open, and `finished()` and `succeeded()` in the Vue helpers wait for it. The runs list shows it as `finishing` ("Finishing…"). (#56)

### Added

- Runs in progress: `GET /docket/runs?name=…` (`docket.runs`) lists the runs of one name, optionally about one subject, that are active or just finished, as display-ready rows decided on the server: label, current step, state, copy, failure reason, and output and help links. Every row passes the run's `view` policy; `Docket::runs()->scopeUsing()` narrows the query and `->describeUsing()` adjusts rows. It is bounded by `limit` and `docket.runs.scan`, and a new migration adds the indexes it reads. `GET /docket/runs/stream` pushes the list as Server-Sent Events. (#56)
- Stuck runs never vanish from the list: past `docket.runs.slow_after` a row is `slow` ("Taking longer than usual"); past its timeout (`docket.runs.timeout_after`, else the run's stale interval) it is `timed_out` with a reason and an optional `docket.runs.help_url` link. Finished rows linger for `linger_seconds` (failed ones for `failed_linger_seconds`), then drop off. (#56)
- `useDocketRuns(filter)` (headless: the server's rows and the connection state), `<DocketRunsList>` (renders rows as given) and `<DocketRunsWidget>` (both together). Polling is fast while a row is active, slow while idle and paused while the tab is hidden; `mode="streamed"` uses the run stream's transport, now exported as `openEventStream()`, with the same polling fallback. Publish and run the new migration. (#56)
- The snapshot gains `checklist`: the steps as server-driven checklist items (`key`, `label`, `state`, `detail`, `reason`, `required`, `action`), with the failure reason as text from `docket.runs.reasons`. In `stepper`, `<DocketProgress>` and `<DocketRunModal>` take a `steps` slot given `items`, `enabled` and `finished`, so an app can draw the steps with its own checklist component; `checklistItems(run)` maps an older snapshot. Docket takes no UI dependency. (#56)
- `$run->outputLink($url, $label)` records where a run's result lives in the app (an http(s) URL or a path); the runs list offers it on the done row in place of the file link. A nullable `output_link` column, in the same new migration. (#56)
- A list row for a run that failed or finished partial because a child failed shows the failing child's own reason, followed down the tree, instead of the generic `run.child_failed` copy. (#56)

## [0.5.0] - 2026-11-04

### Added

- Named steps: `$run->steps(['prepare' => 'Preparing data', ...])` declares a run's steps up front, and the snapshot gains `steps: [{key, label, status, error?}]` (`pending`, `active`, `done`, `failed`), the same in polling and streamed mode. Advancing a step marks the earlier ones done; a failure marks the current step failed with the error code. Existing fields are unchanged. Publish and run the new migration (a nullable `steps` column). (#54)
- `<DocketProgress>` and `<DocketRunModal>` take `variant`: `bar` (the default, unchanged), `stepper` (every step, with a spinner, checks, an inline error, and a last step for the actions) or `minimal` (a spinner and the current step). (#54)

## [0.4.0] - 2026-11-04

### Added

- Streamed mode: `GET /docket/runs/{token}/stream` pushes the run's snapshot as Server-Sent Events whenever its revision changes, with a heartbeat comment, `X-Accel-Buffering: no` and an `end` event when the run finishes or after `docket.stream.seconds` (60). It runs the same `view` policy as the snapshot. `useDocketRun(source, { mode: 'streamed' })` and `<DocketRunModal mode="streamed">` follow it, reconnect after a timeout and fall back to polling on any stream error or when `EventSource` is missing. Polling stays the default. (#52)
- A browser that opens an expired or invalid output link sees a short "This link has expired" page (403) with a way back, set by `docket.expired_link.url` and `.label`; publish the view with `--tag=docket-views`. JSON requests keep the JSON error. (#52)

## [0.3.0] - 2026-11-03

### Added

- A run can record the file it produced with `$run->output($path, $disk, $name, $mime)`. The snapshot gains `output` (name, type and size, never the location) and signed, short-lived `links.download` (attachment) and `links.view` (inline, for PDFs, images and plain text only), streamed from the disk and checked by the run's `view` policy. `docket.output_link_ttl` sets their lifetime. (#48)
- Vue components ship with the package: `php artisan vendor:publish --tag=docket-vue` copies `useDocketRun`, `<DocketProgress>` and `<DocketRunModal>` to `resources/js/docket`. The modal shows progress, then Download and View, and starts the download through a hidden GET form aimed at a hidden frame, so the page stays put even when a link has expired. `useDocketRun` reads a run once when handed a ticket that already finished (a sync queue, or work that was reused), so its output and links arrive. (#48)
- Publish `resources/adoption/surfaces.json` (adoption surfaces format 1) so standards:adoption can read whether an app tracks queued work through Docket, hand-rolls job tracking, or has no queued jobs. A test keeps every `@api` class listed. (#46)

## [0.2.0] - 2026-10-28

### Fixed

- Carry the current spatie/laravel-multitenancy tenant through work dispatched inside a database transaction, so a job enqueued after the outer commit keeps the tenant it was dispatched in, even when the tenant block ended first. The tenant current at commit is restored afterwards. Without the package, or with no current tenant, dispatch is unchanged.

### Added

- Prune stale and expired runs on separate schedules with `docket:prune --stale` and `--expired`, while keeping both jobs enabled when neither or both options are supplied. (#39)
- Scope run access through an opt-in subject policy and tenant resolver, retaining initiator-only defaults and Laravel's view/update permissions. (#38)

## [0.1.1] - 2026-10-15

### Fixed

- Handle empty current-run stacks explicitly and avoid the deprecated object-storage membership method. (#37)

- Keep generated database connections stable during migration default switches so failed migration writes roll back on the same connection. (#33)
- Create the run primary key before its self-referencing foreign keys when migrating PostgreSQL databases. (#35)

## [0.1.0] - 2026-10-14

### Added

- Preserve defaults under partial route configuration, omit ticket and snapshot route links when routes are disabled, and document SQLite transaction limits and database queue failure cleanup. (#31)
- Find stored runs by read token with `Docket::find()`, correct the installation and fake response examples, and document shared SQLite in-memory connections for tests. (#29)
- Add long-running worker hygiene and query-bound regression coverage, batch tree-log and ancestor fingerprint reads, and reuse each ticket's serialization run without caching permissions. (#13)
- Add the runs, items and logs schema, models, status enums, `HasRuns`, progress value and v1 status snapshot on Docket's database connection. (#4)
- Dispatch jobs, closures, batches and chains through Docket with after-commit enqueueing, unique and idempotent runs, completion callbacks, lifecycle events and read-token-only JSON tickets. (#5)
- Run closures inline with in-process ticket results, durable exception and rollback records, and a scoped current-run stack that resets at queue and Octane boundaries. (#6)
- Report progress, logs, item outcomes, results and checkpoints from inline and queued work, with `Docketable`, lazy tracking, added jobs, cancellation safe points and stable `ItemFinished` events. (#7)
- Track child runs with parent/root relations, policy-filtered trees, atomic progress and status roll-up, detached children and completion that waits for the tree. (#8)
- Cap nesting depth, start independent linked runs, and rescue exact recursion and continuation loops with durable fingerprints, repeat opt-outs and reported `LoopDetected` events. (#9)
- Cancel runs cooperatively, retry retained work with inherited successes and checkpoints, and prune expired runs after `RunExpiring` while failing stale work as `run.stalled`. (#10)
- Fake dispatches and inline runs with `Docket::fake()`, assert reported work with `FakeRun`, and attach isolated fake runs to jobs with `withFakeDocket()`. (#11)
- Read snapshots and cursor-paginated items, logs and children through policy-checked JSON routes, with initiator-only defaults, private responses, polling limits and authorized cancel/retry controls. (#12)
