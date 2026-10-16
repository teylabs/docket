# Changelog

## [0.1.1] - 2026-10-15

### Fixed

- Handle empty current-run stacks explicitly and avoid the deprecated object-storage membership method.

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
