/**
 * Docket snapshot helpers with no framework: the composable and components build on these, and they are what the tests pin.
 * A ticket (what a controller returns) and a snapshot (what the poll link returns) share id, status, poll_after_ms and links.
 */
export type DocketStatus = 'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'cancelled';

export type DocketRun = {
    schema_version: number;
    id: string;
    token: string;
    name: string;
    status: DocketStatus;
    revision: number;
    step?: string | null;
    progress?: { done: number; total: number | null; percent: number | null };
    counts?: { processed: number; succeeded: number; failed: number; skipped: number };
    error?: { code: string; params: Record<string, unknown> } | null;
    output?: { name: string; mime: string; size: number } | null;
    poll_after_ms: number | null;
    links: { poll?: string; cancel?: string; retry?: string; download?: string; view?: string; [key: string]: string | undefined };
};

export const finished = (run: Pick<DocketRun, 'status'> | null | undefined): boolean =>
    run != null && ['succeeded', 'partial', 'failed', 'cancelled'].includes(run.status);

export const succeeded = (run: Pick<DocketRun, 'status'> | null | undefined): boolean => run?.status === 'succeeded';

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
 * Starts a download of an attachment URL through a short-lived hidden GET form aimed at a hidden frame. The page and any
 * dialog stay put, and if the server answers with an error page instead of the file, the error stays out of sight.
 */
export function submitDownload(url: string, doc: Document = document): void {
    const { action, fields } = formFields(url);
    if (!doc.querySelector(`iframe[name="${DOWNLOAD_FRAME}"]`)) {
        const frame = doc.createElement('iframe');
        frame.name = DOWNLOAD_FRAME;
        frame.hidden = true;
        frame.title = 'Downloads';
        doc.body.append(frame);
    }
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
