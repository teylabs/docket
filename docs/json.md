# JSON Status Contract v1

> Planned API for Docket. Each entry is tagged with the milestone it lands in; nothing is released yet.

All timestamps are ISO 8601 UTC. Responses send `Cache-Control: private, no-store`.

### Snapshot (`GET /docket/runs/{token}`, `Run::toArray()`)

| Field | Type | Null | Meaning |
| --- | --- | --- | --- |
| `schema_version` | int | no | `1`. Fields are only ever added within v1. |
| `id` | string | no | The run id (ULID). |
| `token` | string | no | The read token. In policy mode it locates the run; it doesn't grant access. |
| `name` | string | no | The run name. |
| `status` | string | no | `queued`, `running`, `succeeded`, `partial`, `failed` or `cancelled`. |
| `revision` | int | no | Goes up on every change. Clients can skip re-rendering an unchanged revision. |
| `step` | string | yes | The current step label. |
| `progress.done` | int | no | The sum of `count` across processed items, plus each rolled-up child's `done`: work attempted. |
| `progress.total` | int | yes | Expected units, plus each rolled-up child's `total` (a child without one counts as 1); `null` means indeterminate. |
| `progress.percent` | int | yes | 0–100, floored; `null` when `total` is null. |
| `counts.processed` | int | no | `succeeded + failed + skipped` (items, not units). |
| `counts.succeeded` / `failed` / `skipped` | int | no | Items per outcome. |
| `error` | object | yes | `{ code: string, params: object }` when the run failed. |
| `result` | string | yes | The run-level reference from `result()`. |
| `partial` | array | no | Up to 20 of the newest items that have results: `[{ key: string, result: string }]`. Usable before the run finishes. Follow `links.items` for all of them. |
| `cancel_requested` | bool | no | A cancel is pending. |
| `parent_run_id` | string | yes | The run this one was started inside (a nested `run()` or a `Docket::job()` dispatch). |
| `root_run_id` | string | yes | The top of this run's tree; `null` for a top-level run. |
| `retry_of_run_id` | string | yes | The run this one retries. |
| `linked_from_run_id` | string | yes | The run this one was linked from with `->linkedTo()`. It is a new tree, not a child. |
| `children` | object | no | `{ total, running, succeeded, partial, failed }`: counts of this run's direct children (`running` includes queued children; cancelled ones count only in `total`). Never child content. |
| `created_at` | string | no | When the run was dispatched, or started inline. |
| `finished_at` | string | yes | Set at the final status. Clients stop polling. |
| `expires_at` | string | yes | When the run will be deleted; `null` while unfinished under the default TTL. |
| `poll_after_ms` | int | yes | Suggested delay before the next poll; `null` once finished. |
| `links.poll` | string | no | This snapshot. |
| `links.items` | string | no | Cursor-paginated items; accepts `?status=`. |
| `links.logs` | string | no | Cursor-paginated logs. |
| `links.children` | string | absent | Present when the run has children: cursor-paginated child tickets. |
| `links.cancel` | string | absent | Present only when the viewer may cancel and the run is unfinished. |
| `links.retry` | string | absent | Present only when the viewer may retry and the run finished as `partial`, `failed` or `cancelled`. |

Full example: README, "The Status Snapshot". A failed run, for illustration:

```json
{ "status": "failed", "error": { "code": "file.unsupported", "params": {} }, "finished_at": "2026-10-13T14:03:40Z", "poll_after_ms": null, "links": { "poll": "…", "items": "…", "logs": "…", "retry": "…" } }
```

### Ticket (`run()` and dispatch responses, `Ticket::toArray()`)

A strict subset of the snapshot: `schema_version`, `id`, `token`, `name`, `status`, `revision`, `poll_after_ms`, and `links` with `poll` plus `cancel`/`retry` under the same conditions. Never: writer grants, capability secrets, `checkpoint`, item content, subject attributes, or the in-process `result()` of a `Docket::run()` ticket. Example: the top of the README. A `Docket::run()` ticket is usually finished, so it answers `200`. HTTP status: `202` while unfinished, `200` when finished. A `Location` header gives the poll link. This applies when the request expects JSON (`Accept: application/json`, or `$request->expectsJson()`). From 0.2, other requests get a `303` redirect to the watch page instead. The same fields, and nothing more, go into Inertia props, Livewire round trips and queued payloads.

### Items page (`links.items`)

```json
{
  "data": [
    { "key": "row:2", "status": "failed", "code": "row.invalid", "params": {}, "result": null, "count": 1, "finished_at": "2026-10-13T14:02:15Z" }
  ],
  "links": { "next": "…?cursor=eyJpZCI6…", "prev": null }
}
```

At most 100 per page. `status` filter: `pending`, `succeeded`, `failed`, `skipped`.

### Children page (`links.children`)

Cursor-paginated direct children, oldest first, at most 100 per page. Only children the viewer may `view` are listed; the parent's `children` counts still cover all of them. Each entry is the child's ticket plus what a tree view needs:

```json
{
  "data": [
    {
      "schema_version": 1, "id": "01JA8Z…", "token": "…", "name": "shard.5", "status": "running", "revision": 31,
      "step": "stage",
      "progress": { "done": 820, "total": 1000, "percent": 82 },
      "counts": { "processed": 820, "succeeded": 820, "failed": 0, "skipped": 0 },
      "children": { "total": 0, "running": 0, "succeeded": 0, "partial": 0, "failed": 0 },
      "latest_log": { "code": "rows.staged", "params": { "count": 500 }, "created_at": "…" },
      "links": { "poll": "…", "logs": "…" }
    }
  ],
  "links": { "next": null, "prev": null }
}
```

`latest_log` is the newest log entry, or `null`. For more, follow the child's `links.logs`. Accepts `?status=`.

### Logs page (`links.logs`)

`{ "data": [{ "code": "rows.staged", "params": { "count": 500 }, "created_at": "…" }], "links": { "next": null, "prev": null } }`

### Errors

| Code | When |
| --- | --- |
| `404` | Unknown, expired or not viewable: these three can't be told apart. |
| `403` | Viewable, but the viewer may not cancel or retry. |
| `409` | Cancel on a finished run, or retry on an unfinished or succeeded run. |
| `429` | Polled faster than allowed; `Retry-After` is set. |

### Reserved codes

`run.failed` (an unkeyed job threw after its last try), `run.exception` (an exception left a `Docket::run()` closure; its class name is logged, never its message), `run.rolled_back` (the transaction around a `run()` or `dispatch()` rolled back), `run.child_failed` (a child's exception went uncaught out of the parent; `params.child` is the child's id), `run.loop_detected` (a continuation or child repeated earlier work exactly; `params.job` and `params.repeats`), `run.depth_exceeded` (a nested `run()` or `Docket::job()` would have exceeded `docket.max_depth`; `params.max_depth`), `run.stalled` (no report within `staleAfter`), `run.job_failed` (the item code when a keyed job threw after its last try). App codes are `area.reason` and must not start with `run.`.
