# Changelog

## Unreleased

### Added

- Add the runs, items and logs schema, models, status enums, `HasRuns`, progress value and v1 status snapshot on Docket's database connection. (#4)
- Dispatch jobs, closures, batches and chains through Docket with after-commit enqueueing, unique and idempotent runs, completion callbacks, lifecycle events and read-token-only JSON tickets. (#5)
