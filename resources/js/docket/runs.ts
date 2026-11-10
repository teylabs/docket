/**
 * The runs-in-progress list, as the server describes it. The server decides every row (which runs, label, step, state, copy,
 * reason and links) and the poll cadence; these helpers only build the request, keep a finished row on screen until its
 * `linger_until`, and pick the next poll delay. They are what the tests pin.
 */
import type { DocketStep } from './run';

/** A row's state. `slow` and `timed_out` are active runs past the server's thresholds; the rest are run statuses. */
export type DocketRunRowState = 'queued' | 'running' | 'slow' | 'timed_out' | 'finishing' | 'succeeded' | 'partial' | 'failed' | 'cancelled';

/** A link the server offers on a row, with its copy. `output` once the run produced a file; `help` on a timed-out or failed run. */
export type DocketRowLink = { url: string; label: string };

/** A child run that failed, with the reason to show and whether its parent required it. */
export type DocketFailedChild = { id: string; name: string; label: string; required: boolean; code: string | null; reason: string };

/** One row, exactly as `GET /docket/runs` sends it (`Tey\Docket\Listing\RunList::row()`). */
export type DocketRunRow = {
    id: string;
    token: string;
    name: string;
    label: string;
    status: string;
    state: DocketRunRowState;
    active: boolean;
    tone: 'neutral' | 'warning' | 'danger' | 'success';
    message: string;
    reason: string | null;
    /** The error code behind a failure, for logs and support; not for display. */
    code: string | null;
    /** Direct children that failed: optional ones leave the run `partial`; a required one failed it. Since 0.6.2. */
    failed_children: DocketFailedChild[];
    step: { key: string; label: string } | null;
    steps: DocketStep[];
    progress: { done: number; total: number | null; percent: number | null };
    started_at: string;
    finished_at: string | null;
    slow_at: string | null;
    timeout_at: string | null;
    linger_until: string | null;
    links: { output?: DocketRowLink; help?: DocketRowLink; [key: string]: DocketRowLink | undefined };
};

/** The list endpoint's answer, and each `runs` event of its stream. */
export type DocketRunList = {
    schema_version: number;
    runs: DocketRunRow[];
    active: boolean;
    empty: string;
    poll_after_ms: number;
    generated_at: string;
};

/** Which runs to list: a run name (the type), optionally one subject, a row limit and a linger in seconds. */
export type DocketRunFilter = {
    name: string;
    subjectType?: string | null;
    subjectId?: string | number | null;
    limit?: number | null;
    linger?: number | null;
};

/** The list URL for a filter under the package's route prefix; `stream` gives the event stream. */
export function runsUrl(filter: DocketRunFilter, options: { prefix?: string; stream?: boolean } = {}): string {
    const prefix = `/${(options.prefix ?? 'docket').replace(/^\/+|\/+$/g, '')}`.replace(/^\/$/, '');
    const query = new URLSearchParams({ name: filter.name });
    if (filter.subjectType != null) query.set('subject_type', String(filter.subjectType));
    if (filter.subjectId != null) query.set('subject_id', String(filter.subjectId));
    if (filter.limit != null) query.set('limit', String(filter.limit));
    if (filter.linger != null) query.set('linger', String(filter.linger));

    return `${prefix}/runs${options.stream ? '/stream' : ''}?${query.toString()}`;
}

/** The difference between the server's clock and this one, from a list's `generated_at`, so linger times read on the server's clock. */
export const clockSkew = (list: Pick<DocketRunList, 'generated_at'>, receivedAt: number = Date.now()): number => {
    const server = Date.parse(list.generated_at);

    return Number.isFinite(server) ? server - receivedAt : 0;
};

/** The rows to show now: every row whose `linger_until` (server time) has not passed. Active rows have none and always stay. */
export const visibleRows = (rows: DocketRunRow[], now: number = Date.now(), skew = 0): DocketRunRow[] =>
    rows.filter(row => row.linger_until === null || Date.parse(row.linger_until) > now + skew);

/**
 * How long until the next lingering row drops off (server time read through `skew`), or null when no row lingers:
 * the list needs a clock only then, so an idle list keeps no timer.
 */
export function nextLingerDelay(rows: DocketRunRow[], now: number = Date.now(), skew = 0): number | null {
    let soonest: number | null = null;
    for (const row of rows) {
        if (row.linger_until === null) continue;
        const left = Date.parse(row.linger_until) - (now + skew);
        if (Number.isFinite(left) && left > 0) soonest = soonest === null ? left : Math.min(soonest, left);
    }
    return soonest === null ? null : Math.max(250, Math.ceil(soonest) + 50);
}

/** Whether two answers show the same rows and cadence, so an unchanged idle poll re-renders nothing. */
export const sameList = (a: DocketRunList | null, b: DocketRunList): boolean =>
    a !== null && a.active === b.active && a.empty === b.empty && a.poll_after_ms === b.poll_after_ms && JSON.stringify(a.runs) === JSON.stringify(b.runs);

/** The wait before the next poll: the server's hint (fast while a row is active, slow while idle), a 429's Retry-After, never under 250 ms. */
export function nextListDelay(list: Pick<DocketRunList, 'poll_after_ms'> | null, retryAfterSeconds?: number | null): number {
    if (retryAfterSeconds != null && Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0) return Math.max(250, retryAfterSeconds * 1000);

    return Math.max(250, list?.poll_after_ms ?? 2000);
}
