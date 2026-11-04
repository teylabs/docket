<script setup lang="ts">
import { computed, ref } from 'vue';
import { checklistItems, finished, freshDownloadLink, submitDownload, succeeded, type DocketMode, type DocketRun } from './run';
import { useDocketRun } from './useDocketRun';

/**
 * The body of a dialog that follows one run, for a modal the host opens (an InertiaUI modal, say) with the run's snapshot as a
 * prop (`$ticket->snapshot()`): it paints that snapshot at once, then streams the run, falling back to polling. No heading: the
 * modal owns the title.
 *
 * - While running or done: the `steps` slot gets `items` (the server's checklist items), `enabled` (the run succeeded and
 *   settled) and `finished`; pass them to your checklist component. The `actions` slot gets `run`, `enabled`, `download`
 *   and `retry`; by default Download and View appear once the run succeeded with an output.
 * - Failed: only the failed step (its reason left out of the item), one alert with the server's reason (`failure.reason`), and
 *   Retry when the viewer may retry. No download.
 */
const props = withDefaults(defineProps<{
    run: DocketRun | null;
    mode?: DocketMode;
    download?: (url: string) => void;
    labels?: Partial<Record<'download' | 'view' | 'retry' | 'expired', string>>;
}>(), { mode: 'streamed', download: undefined, labels: () => ({}) });

const { run, unavailable, retry } = useDocketRun(() => props.run, { mode: props.mode });
const text = computed(() => ({ download: 'Download', view: 'View', retry: 'Try again', expired: 'This file is no longer available.', ...props.labels }));
const expired = ref(false);
const failed = computed(() => run.value?.status === 'failed' && finished(run.value));
const items = computed(() => {
    const all = checklistItems(run.value);

    return failed.value ? all.filter(item => item.state === 'failed').map(item => ({ ...item, reason: null })) : all;
});
const reason = computed(() => run.value?.failure?.reason ?? 'Something went wrong.');

async function startDownload(): Promise<void> {
    if (!run.value) return;
    const link = await freshDownloadLink(run.value);
    expired.value = link === null;
    if (link) (props.download ?? submitDownload)(link);
}
</script>

<template>
    <div data-docket-run-dialog :data-status="unavailable ? 'unavailable' : run?.status ?? 'queued'">
        <slot name="steps" :items="items" :enabled="succeeded(run)" :finished="finished(run)" :run="run">
            <ol v-if="items.length" data-docket-steps>
                <li v-for="item in items" :key="item.key" data-docket-step :data-status="item.state" :aria-current="item.state === 'active' ? 'step' : undefined">
                    <span data-docket-step-label>{{ item.label }}</span>
                    <span v-if="item.reason" data-docket-step-reason>{{ item.reason }}</span>
                </li>
            </ol>
        </slot>
        <p v-if="failed" role="alert" data-docket-failure>{{ reason }}</p>
        <slot name="actions" :run="run" :enabled="succeeded(run)" :failed="failed" :download="startDownload" :retry="retry">
            <button v-if="failed && run?.links.retry" type="button" data-docket-retry @click="retry">{{ text.retry }}</button>
            <div v-else-if="succeeded(run) && run?.output" data-docket-output>
                <button type="button" data-docket-download @click="startDownload">{{ text.download }}</button>
                <a v-if="run.links.view" :href="run.links.view" target="_blank" rel="noopener" data-docket-view>{{ text.view }}</a>
            </div>
        </slot>
        <p v-if="expired" role="alert" data-docket-expired>{{ text.expired }}</p>
    </div>
</template>
