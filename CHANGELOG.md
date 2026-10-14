# Changelog

## Unreleased

### Added

- Add the runs, items and logs schema, models, status enums, `HasRuns`, progress value and v1 status snapshot on Docket's database connection. (#4)
- Dispatch jobs, closures, batches and chains through Docket with after-commit enqueueing, unique and idempotent runs, completion callbacks, lifecycle events and read-token-only JSON tickets. (#5)
- Run closures inline with in-process ticket results, durable exception and rollback records, and a scoped current-run stack that resets at queue and Octane boundaries. (#6)
- Report progress, logs, item outcomes, results and checkpoints from inline and queued work, with `Docketable`, lazy tracking, added jobs, cancellation safe points and stable `ItemFinished` events. (#7)
- Track child runs with parent/root relations, policy-filtered trees, atomic progress and status roll-up, detached children and completion that waits for the tree. (#8)
- Cap nesting depth, start independent linked runs, and rescue exact recursion and continuation loops with durable fingerprints, repeat opt-outs and reported `LoopDetected` events. (#9)
