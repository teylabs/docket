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

/** Starts a download of an attachment URL through a short-lived hidden GET form; the page and any dialog stay put. */
export function submitDownload(url: string, doc: Document = document): void {
    const { action, fields } = formFields(url);
    const form = doc.createElement('form');
    form.method = 'GET';
    form.action = action;
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
