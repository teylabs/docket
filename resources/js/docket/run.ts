/**
 * Docket snapshot helpers with no framework: the composable and components build on these, and they are what the tests pin.
 * A ticket (what a controller returns) and a snapshot (what the poll link returns) share id, status, poll_after_ms and links.
 */
export type DocketStatus = 'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'cancelled';

export type DocketStepStatus = 'pending' | 'active' | 'done' | 'failed';

/** One of the steps the work declared up front (`$run->steps([...])`), with where the run is. */
export type DocketStep = { key: string; label: string; status: DocketStepStatus; error?: string };

/** How a progress component looks: `bar` (the default), `stepper` (every step listed) or `minimal` (a spinner and the current step). */
export type DocketVariant = 'bar' | 'stepper' | 'minimal';

/** A step in the checklist shape: `state` is the step status, `reason` the server's failure reason. */
export type DocketChecklistItem = {
    key: string;
    label: string;
    state: 'pending' | 'active' | 'done' | 'failed' | 'skipped';
    detail: string | null;
    reason: string | null;
    /** The error code behind a failed step, for logs and support; not for display. Since 0.6.1. */
    code?: string | null;
    required: boolean;
    action: { label: string; url?: string | null; name?: string | null } | null;
};

export type DocketRun = {
    schema_version: number;
    id: string;
    token: string;
    name: string;
    status: DocketStatus;
    revision: number;
    step?: string | null;
    steps?: DocketStep[];
    progress?: { done: number; total: number | null; percent: number | null };
    counts?: { processed: number; succeeded: number; failed: number; skipped: number };
    error?: { code: string; params: Record<string, unknown> } | null;
    output?: { name: string; mime: string; size: number } | null;
    /** The steps as checklist items, ready for a server-driven checklist component (kit's `<Checklist :items>`). Since 0.6. */
    checklist?: DocketChecklistItem[];
    /** Direct children that failed, with their reasons (0.6.2). */
    failed_children?: Array<{ id: string; name: string; label: string; required: boolean; code: string | null; reason: string }>;
    /** The run has its final status, but its completion callbacks (which often record the output) are still running. Keep reading. */
    settling?: boolean;
    poll_after_ms: number | null;
    links: { poll?: string; cancel?: string; retry?: string; download?: string; view?: string; [key: string]: string | undefined };
};

/** Whether the run is over: a final status, and the server no longer reports it `settling`. */
export const finished = (run: Pick<DocketRun, 'status' | 'settling'> | null | undefined): boolean =>
    run != null && run.settling !== true && ['succeeded', 'partial', 'failed', 'cancelled'].includes(run.status);

/** A ticket (what a controller returns) carries only the run's identity and status; a snapshot (the poll link's answer) has its progress. */
export const isTicket = (run: DocketRun): boolean => !('progress' in run);

export const succeeded = (run: Pick<DocketRun, 'status' | 'settling'> | null | undefined): boolean => run?.status === 'succeeded' && run.settling !== true;

/** A determinate percentage only when the run knows its total; null asks for an indeterminate bar. */
export function percent(run: DocketRun | null | undefined): number | null {
    const progress = run?.progress;
    if (!progress || progress.total === null || progress.total <= 0) return finished(run) ? 100 : null;

    return Math.max(0, Math.min(100, progress.percent ?? Math.round((progress.done / progress.total) * 100)));
}

/** The wait before the next poll: the run's own hint, or the server's Retry-After on 429, never under 250 ms. */
export function nextDelay(run: DocketRun, retryAfterSeconds?: number | null): number {
    if (retryAfterSeconds != null && Number.isFinite(retryAfterSeconds)) return Math.max(250, retryAfterSeconds * 1000);

    return Math.max(250, run.poll_after_ms ?? 2000);
}

/** A newer revision replaces the snapshot; an older or equal one (a late response) is ignored. */
export const newer = (current: DocketRun | null, incoming: DocketRun): boolean =>
    current === null || current.id !== incoming.id || incoming.revision > current.revision || finished(incoming) && !finished(current);

/**
 * A GET form drops the query string of its action, so a signed URL becomes the form's path plus one hidden input per
 * query parameter. Submitting it downloads an attachment without leaving the page or opening a window.
 */
export function formFields(url: string): { action: string; fields: Array<[string, string]> } {
    const parsed = new URL(url, 'http://localhost');
    const action = /^https?:\/\//.test(url) ? `${parsed.origin}${parsed.pathname}` : parsed.pathname;

    return { action, fields: [...parsed.searchParams.entries()] };
}

/** The name of the hidden frame downloads go through: an error answer (an expired link) lands there, not over the page. */
export const DOWNLOAD_FRAME = 'docket-download';

/**
 * Makes sure the hidden download frame exists and returns its name. Call it before aiming any form at the frame: a form whose
 * target names a missing frame opens a new window, which the popup blocker stops when no click is behind it.
 */
export function ensureDownloadFrame(doc: Document = document): string {
    if (!doc.querySelector(`iframe[name="${DOWNLOAD_FRAME}"]`)) {
        const frame = doc.createElement('iframe');
        frame.name = DOWNLOAD_FRAME;
        frame.hidden = true;
        frame.title = 'Downloads';
        doc.body.append(frame);
    }

    return DOWNLOAD_FRAME;
}

/**
 * Starts a download of an attachment URL through a short-lived hidden GET form aimed at a hidden frame. The page and any
 * dialog stay put, and if the server answers with an error page instead of the file, the error stays out of sight.
 */
export function submitDownload(url: string, doc: Document = document): void {
    const { action, fields } = formFields(url);
    ensureDownloadFrame(doc);
    const form = doc.createElement('form');
    form.method = 'GET';
    form.action = action;
    form.target = DOWNLOAD_FRAME;
    form.hidden = true;
    for (const [name, value] of fields) {
        const input = doc.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    }
    doc.body.append(form);
    form.submit();
    form.remove();
}

/**
 * The download link to use now. Signed links expire, and a finished run is no longer polled, so read the run once more for a
 * freshly signed link; if that read fails (rate limited, offline), fall back to the link already held.
 */
export async function freshDownloadLink(run: DocketRun, read: (url: string) => Promise<Response> = url => fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })): Promise<string | null> {
    if (run.links.poll) {
        try {
            const response = await read(run.links.poll);
            if (response.ok) return ((await response.json()) as DocketRun).links.download ?? null;
            if (response.status === 404 || response.status === 403) return null;
        } catch {
            // Offline or blocked: the held link may still be valid.
        }
    }

    return run.links.download ?? null;
}

const downloadedRuns = new Set<string>();

/** Whether the automatic download for this run has already started on this page, across remounts of the dialog. Marks it. */
export function claimAutoDownload(runId: string): boolean {
    if (downloadedRuns.has(runId)) return false;
    downloadedRuns.add(runId);

    return true;
}

/** How a watcher follows a run: `polling` reads the `poll` link on a timer; `streamed` listens to its Server-Sent Events. */
export type DocketMode = 'streamed' | 'polling';

/** The run's event stream, next to its `poll` link; null when the run has no routes. */
export const streamUrl = (run: Pick<DocketRun, 'links'>): string | null => (run.links.poll ? `${run.links.poll}/stream` : null);

export type EventSourceLike = {
    addEventListener(type: string, listener: (event: { data: string }) => void): void;
    onerror: ((event: unknown) => void) | null;
    close(): void;
};

/** The browser's EventSource, or a stand-in with the same surface (tests). */
export type EventSourceConstructor = new (url: string, init?: { withCredentials?: boolean }) => EventSourceLike;

const defaultSource = (): EventSourceConstructor | undefined => (globalThis as { EventSource?: EventSourceConstructor }).EventSource;

/**
 * Listens to a Docket event stream at `url`. Each `event` event's JSON goes to `message`. The server ends a stream with an `end`
 * event: `finished` closes it, `timeout` reconnects at once, and `unavailable`, any connection error or a garbled event closes it
 * for good and calls `fallback` once, so the caller can poll instead. Returns a function that closes the stream, or null when
 * streaming isn't possible (no EventSource), in which case the caller should poll. Runs and run lists share this transport.
 */
export function openEventStream<T>(
    url: string,
    event: string,
    handlers: { message: (payload: T) => void; fallback: () => void },
    Source: EventSourceConstructor | undefined = defaultSource(),
): (() => void) | null {
    if (typeof Source !== 'function') return null;
    let source: EventSourceLike | null = null;
    let closed = false;

    const close = () => {
        closed = true;
        source?.close();
    };
    const fail = () => {
        if (closed) return;
        close();
        handlers.fallback();
    };
    const connect = () => {
        const current = new Source(url, { withCredentials: true });
        source = current;
        current.addEventListener(event, message => {
            try {
                handlers.message(JSON.parse(message.data) as T);
            } catch {
                fail();
            }
        });
        current.addEventListener('end', message => {
            let reason: unknown = null;
            try {
                reason = (JSON.parse(message.data) as { reason?: unknown }).reason;
            } catch {
                // An unreadable end is treated as unavailable.
            }
            if (reason === 'timeout' && !closed) {
                current.close();
                connect();
            } else if (reason === 'finished') {
                close();
            } else {
                fail();
            }
        });
        // EventSource would reconnect by itself after an error; polling is the safer fallback (a proxy that buffers or cuts streams).
        current.onerror = () => fail();
    };

    connect();

    return close;
}

/**
 * Listens to a run's event stream: each `snapshot` event goes to `snapshot`, with the reconnect and fallback rules of
 * `openEventStream`. Returns null when streaming isn't possible (no EventSource, or no routes); the caller should poll.
 */
export function openStream(
    run: Pick<DocketRun, 'links'>,
    handlers: { snapshot: (run: DocketRun) => void; fallback: () => void },
    Source: EventSourceConstructor | undefined = defaultSource(),
): (() => void) | null {
    const url = streamUrl(run);
    if (!url) return null;

    return openEventStream<DocketRun>(url, 'snapshot', { message: handlers.snapshot, fallback: handlers.fallback }, Source);
}

/** The run's declared steps, each label replaced by the caller's own for that key when given. Empty when the work declared none. */
export function stepList(run: DocketRun | null | undefined, labels: Record<string, string> = {}): DocketStep[] {
    return (run?.steps ?? []).map(step => ({ ...step, label: labels[step.key] ?? step.label }));
}

/** The label of the step the run is on: the active (or failed) declared step, else its `step` key through `labels`; null before any step. */
export function currentStepLabel(run: DocketRun | null | undefined, labels: Record<string, string> = {}): string | null {
    const current = stepList(run, labels).find(step => step.status === 'active' || step.status === 'failed');
    if (current) return current.label;

    return run?.step ? labels[run.step] ?? run.step : null;
}

/**
 * The run's steps as checklist items: the server's `checklist` when sent, else its `steps` mapped (`state` is `status`, `reason`
 * is `error`) for a server older than 0.6. Empty when the work declared none.
 */
export function checklistItems(run: DocketRun | null | undefined): DocketChecklistItem[] {
    if (run?.checklist) return run.checklist;

    return (run?.steps ?? []).map(step => ({ key: step.key, label: step.label, state: step.status, detail: null, reason: step.error ? 'Something went wrong.' : null, code: step.error ?? null, required: true, action: null }));
}
