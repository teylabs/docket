import { onBeforeUnmount, ref, shallowRef, toValue, watch, type MaybeRefOrGetter } from 'vue';
import { finished, isTicket, newer, nextDelay, openStream, type DocketMode, type DocketRun } from './run';

export type DocketRunState = {
    /** The live snapshot (or the ticket until the first poll answers). */
    run: Readonly<import('vue').ShallowRef<DocketRun | null>>;
    /** Set when the run can no longer be read (gone, expired, forbidden) or the network keeps failing. */
    unavailable: Readonly<import('vue').Ref<boolean>>;
    /** Follow another ticket or snapshot, e.g. the one a POST returned. */
    follow: (run: DocketRun | null) => void;
    /** Retry a failed, partial or cancelled run through its retry link and follow the new run. */
    retry: () => Promise<void>;
    stop: () => void;
};

function xsrfToken(): string | null {
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function request(url: string, method: 'GET' | 'POST'): Promise<Response> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = method === 'POST' ? xsrfToken() : null;
    if (token) headers['X-XSRF-TOKEN'] = token;

    return fetch(url, { method, headers, credentials: 'same-origin' });
}

export type DocketRunOptions = {
    /** `polling` (the default) reads the `poll` link on a timer; `streamed` listens to the run's event stream and polls if that fails. */
    mode?: DocketMode;
};

/**
 * Follows a Docket run: polls its `poll` link at `poll_after_ms`, waits out a 429's Retry-After, ignores late responses and
 * stops once the run finishes or the component unmounts. Pass a ticket or snapshot, a ref of one, or a getter (an Inertia prop).
 * With `{ mode: 'streamed' }` it listens to the run's Server-Sent Events instead, and falls back to polling that run on any
 * stream error, or at once when the browser has no EventSource.
 */
export function useDocketRun(source: MaybeRefOrGetter<DocketRun | null | undefined> = null, options: DocketRunOptions = {}): DocketRunState {
    const mode = options.mode ?? 'polling';
    const run = shallowRef<DocketRun | null>(null);
    const unavailable = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let generation = 0;
    let failures = 0;
    let closeStream: (() => void) | null = null;

    const stop = () => {
        generation++;
        if (timer) clearTimeout(timer);
        timer = null;
        closeStream?.();
        closeStream = null;
    };

    /** Takes a snapshot that is newer than the one held, or the first snapshot for a ticket. */
    const accept = (incoming: DocketRun) => {
        if (newer(run.value, incoming) || (run.value !== null && isTicket(run.value) && incoming.id === run.value.id)) run.value = incoming;
    };

    const stream = (current: number) => {
        closeStream = openStream(run.value!, {
            snapshot: incoming => {
                if (current !== generation) return;
                accept(incoming);
                if (finished(run.value) && !isTicket(run.value!)) {
                    closeStream?.();
                    closeStream = null;
                }
            },
            fallback: () => {
                if (current !== generation) return;
                closeStream = null;
                void poll(current);
            },
        });
        if (!closeStream) void poll(current);
    };

    const schedule = (delay: number, current: number) => {
        timer = setTimeout(() => void poll(current), delay);
    };

    async function poll(current: number): Promise<void> {
        const snapshot = run.value;
        // A finished snapshot needs nothing more; a finished ticket still lacks its output and links, so it is read once.
        if (current !== generation || !snapshot || (finished(snapshot) && !isTicket(snapshot)) || !snapshot.links.poll) return;
        try {
            const response = await request(snapshot.links.poll, 'GET');
            if (current !== generation) return;
            if (response.status === 429) return schedule(nextDelay(snapshot, Number(response.headers.get('Retry-After'))), current);
            if (!response.ok) {
                unavailable.value = true;
                return;
            }
            failures = 0;
            const incoming = (await response.json()) as DocketRun;
            if (current !== generation) return;
            accept(incoming);
            if (!finished(run.value)) schedule(nextDelay(run.value!), current);
        } catch {
            if (current !== generation) return;
            failures++;
            if (failures >= 5) unavailable.value = true;
            else schedule(nextDelay(snapshot) * 2 ** failures, current);
        }
    }

    const follow = (next: DocketRun | null) => {
        stop();
        failures = 0;
        unavailable.value = false;
        run.value = next;
        if (next && (!finished(next) || isTicket(next))) {
            if (mode === 'streamed') stream(generation);
            else void poll(generation);
        }
    };

    async function retry(): Promise<void> {
        const link = run.value?.links.retry;
        if (!link) return;
        const response = await request(link, 'POST');
        if (!response.ok) {
            unavailable.value = response.status !== 409;
            return;
        }
        follow((await response.json()) as DocketRun);
    }

    watch(() => toValue(source) ?? null, next => {
        if (next && (!run.value || next.id !== run.value.id)) follow(next);
    }, { immediate: true });
    onBeforeUnmount(stop);

    return { run, unavailable, follow, retry, stop };
}
