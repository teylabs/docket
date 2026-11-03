<script setup lang="ts">
import { computed } from 'vue';
import { finished, percent, type DocketRun } from './run';

/**
 * A run's progress: the step, a determinate bar when the total is known (indeterminate otherwise), counts, and a final state
 * with Retry. Unstyled: style it through the data-docket-* hooks, or replace parts through the slots.
 */
const props = withDefaults(defineProps<{
    run: DocketRun | null;
    /** Labels for the run's step keys; an unlisted step shows as written. */
    steps?: Record<string, string>;
    unavailable?: boolean;
    labels?: Partial<Record<'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'cancelled' | 'unavailable' | 'retry', string>>;
}>(), { steps: () => ({}), unavailable: false, labels: () => ({}) });
const emit = defineEmits<{ retry: [] }>();

const text = computed(() => ({
    queued: 'Waiting to start…', running: 'Working…', succeeded: 'Done', partial: 'Finished with some problems',
    failed: 'Something went wrong', cancelled: 'Cancelled', unavailable: 'Progress is no longer available', retry: 'Try again',
    ...props.labels,
}));
const value = computed(() => percent(props.run));
const step = computed(() => (props.run?.step ? props.steps[props.run.step] ?? props.run.step : null));
const status = computed(() => (props.unavailable ? 'unavailable' : props.run?.status ?? 'queued'));
const message = computed(() => (status.value === 'running' || status.value === 'queued') && step.value ? step.value : text.value[status.value]);
const counts = computed(() => props.run?.counts);
</script>

<template>
    <div data-docket-progress :data-status="status">
        <p data-docket-message role="status" aria-live="polite">{{ message }}</p>
        <progress data-docket-bar max="100" :value="value ?? undefined" :aria-label="message">{{ value === null ? '' : `${value}%` }}</progress>
        <p v-if="run?.progress?.total" data-docket-count>{{ run.progress.done.toLocaleString() }} of {{ run.progress.total.toLocaleString() }}</p>
        <p v-if="counts && (counts.failed || counts.skipped)" data-docket-problems>
            <span v-if="counts.failed">{{ counts.failed }} failed</span><span v-if="counts.failed && counts.skipped"> · </span><span v-if="counts.skipped">{{ counts.skipped }} skipped</span>
        </p>
        <slot v-if="finished(run) || unavailable" name="final" :run="run" :status="status">
            <button v-if="run?.links.retry && status !== 'succeeded'" type="button" data-docket-retry @click="emit('retry')">{{ text.retry }}</button>
        </slot>
    </div>
</template>
