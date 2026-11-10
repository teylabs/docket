import { computed, onBeforeUnmount, ref, shallowRef, toValue, watch, type ComputedRef, type MaybeRefOrGetter, type Ref, type ShallowRef } from 'vue';
import { openEventStream, type DocketMode } from './run';
import { clockSkew, nextLingerDelay, nextListDelay, runsUrl, sameList, visibleRows, type DocketRunFilter, type DocketRunList, type DocketRunRow } from './runs';

export type DocketRunsState = {
    /** The rows to show now, as the server described them, minus finished rows past their `linger_until`. */
    rows: ComputedRef<DocketRunRow[]>;
    /** The server's last answer (rows, the empty-list copy, the cadence), or null before the first one. */
    list: Readonly<ShallowRef<DocketRunList | null>>;
    /** `streaming`, `polling`, `paused` (the tab is hidden) or `unavailable` (the list can't be read). */
    connection: Readonly<Ref<'idle' | 'streaming' | 'polling' | 'paused' | 'unavailable'>>;
    /** Read the list now. */
    refresh: () => Promise<void>;
    stop: () => void;
};

export type DocketRunsOptions = {
    /** `polling` (the default) reads the list at the server's `poll_after_ms`; `streamed` listens to its event stream and polls if that fails. */
    mode?: DocketMode;
    /** The package's route prefix (`docket.routes.prefix`). */
    prefix?: string;
};

type Doc = { hidden: boolean; addEventListener(type: string, listener: () => void): void; removeEventListener(type: string, listener: () => void): void };

/**
 * Follows the runs-in-progress list for a filter. Headless: it returns the server's row descriptors and the connection state,
 * and decides nothing about them. It polls fast while any row is active and slowly while idle (both from the server), waits out
 * a 429, pauses while the tab is hidden and resumes when it is shown, keeps no clock unless a finished row is lingering, and in `streamed` mode listens to the list's event stream,
 * falling back to polling on any stream error. A finished row stays until its `linger_until`, then drops off.
 */
export function useDocketRuns(filter: MaybeRefOrGetter<DocketRunFilter>, options: DocketRunsOptions = {}): DocketRunsState {
    const mode = options.mode ?? 'polling';
    const list = shallowRef<DocketRunList | null>(null);
    const connection = ref<'idle' | 'streaming' | 'polling' | 'paused' | 'unavailable'>('idle');
    const now = ref(Date.now());
    let skew = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let clock: ReturnType<typeof setTimeout> | null = null;
    let closeStream: (() => void) | null = null;
    let generation = 0;
    let failures = 0;
    const doc: Doc | null = typeof document === 'undefined' ? null : (document as unknown as Doc);

    const stopClock = () => {
        if (clock) clearTimeout(clock);
        clock = null;
    };

    // Only the linger display needs a clock, and only until the next lingering row drops off: one timeout to that moment,
    // none while nothing lingers or the tab is hidden. The server decides every state.
    const tick = () => {
        stopClock();
        if (doc?.hidden) return;
        const delay = nextLingerDelay(list.value?.runs ?? [], Date.now(), skew);
        if (delay !== null) {
            clock = setTimeout(() => {
                clock = null;
                now.value = Date.now();
                tick();
            }, delay);
        }
    };

    const accept = (incoming: DocketRunList) => {
        skew = clockSkew(incoming);
        now.value = Date.now();
        // An unchanged answer (the usual idle poll) keeps the same list, so nothing downstream re-renders.
        if (!sameList(list.value, incoming)) list.value = incoming;
        tick();
    };

    const halt = () => {
        generation++;
        if (timer) clearTimeout(timer);
        timer = null;
        closeStream?.();
        closeStream = null;
    };

    const schedule = (delay: number, current: number) => {
        timer = setTimeout(() => void poll(current), delay);
    };

    async function poll(current: number): Promise<void> {
        if (current !== generation) return;
        connection.value = 'polling';
        try {
            const response = await fetch(runsUrl(toValue(filter), { prefix: options.prefix }), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (current !== generation) return;
            if (response.status === 429) return schedule(nextListDelay(list.value, Number(response.headers.get('Retry-After'))), current);
            if (!response.ok) {
                connection.value = 'unavailable';
                return;
            }
            failures = 0;
            const incoming = (await response.json()) as DocketRunList;
            if (current !== generation) return;
            accept(incoming);
            schedule(nextListDelay(incoming), current);
        } catch {
            if (current !== generation) return;
            failures++;
            if (failures >= 5) connection.value = 'unavailable';
            else schedule(nextListDelay(list.value) * 2 ** failures, current);
        }
    }

    const start = () => {
        halt();
        failures = 0;
        const current = generation;
        if (doc?.hidden) {
            connection.value = 'paused';
            return;
        }
        if (mode === 'streamed') {
            closeStream = openEventStream<DocketRunList>(runsUrl(toValue(filter), { prefix: options.prefix, stream: true }), 'runs', {
                message: incoming => {
                    if (current === generation) accept(incoming);
                },
                fallback: () => {
                    if (current !== generation) return;
                    closeStream = null;
                    void poll(current);
                },
            });
            if (closeStream) {
                connection.value = 'streaming';
                return;
            }
        }
        void poll(current);
    };

    const onVisibility = () => {
        if (doc?.hidden) {
            halt();
            stopClock();
            connection.value = 'paused';
            return;
        }
        now.value = Date.now();
        tick();
        start();
    };

    const stop = () => {
        halt();
        stopClock();
        doc?.removeEventListener('visibilitychange', onVisibility);
        connection.value = 'idle';
    };

    const rows = computed(() => visibleRows(list.value?.runs ?? [], now.value, skew));

    // Nothing to follow while rendering on the server: the browser starts it after hydration.
    if (typeof window !== 'undefined') {
        watch(() => JSON.stringify(toValue(filter)), start, { immediate: true });
        doc?.addEventListener('visibilitychange', onVisibility);
        onBeforeUnmount(stop);
    }

    return { rows, list, connection, refresh: () => poll(generation), stop };
}
