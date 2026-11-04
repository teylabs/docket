<script setup lang="ts">
import { computed } from 'vue';
import { checklistItems, currentStepLabel, finished, percent, stepList, succeeded, type DocketRun, type DocketVariant } from './run';

/**
 * A run's progress, in one of three variants. `bar` (the default): the step, a determinate bar when the total is known
 * (indeterminate otherwise), counts, and a final state with Retry. `stepper`: every declared step, pending ones muted, the
 * active one with a spinner, done ones with a check, a failed one with its error, then a last step holding the `actions` slot
 * (enabled once the run finishes). `minimal`: a spinner and the current step. Unstyled: style it through the data-docket-*
 * hooks, or replace parts through the slots. In `stepper`, the `steps` slot replaces the whole list with your own renderer, given
 * `items` (the server's checklist items), `enabled` and `finished`: pass them to kit's `<Checklist :items>`, for instance.
 */
const props = withDefaults(defineProps<{
    run: DocketRun | null;
    /** Labels for the run's step keys; an unlisted step shows as written. */
    steps?: Record<string, string>;
    unavailable?: boolean;
    labels?: Partial<Record<'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'cancelled' | 'unavailable' | 'retry', string>>;
    variant?: DocketVariant;
}>(), { steps: () => ({}), unavailable: false, labels: () => ({}), variant: 'bar' });
const emit = defineEmits<{ retry: [] }>();

const text = computed(() => ({
    queued: 'Waiting to start…', running: 'Working…', succeeded: 'Done', partial: 'Finished with some problems',
    failed: 'Something went wrong', cancelled: 'Cancelled', unavailable: 'Progress is no longer available', retry: 'Try again',
    ...props.labels,
}));
const value = computed(() => percent(props.run));
const step = computed(() => currentStepLabel(props.run, props.steps));
const list = computed(() => stepList(props.run, props.steps));
const over = computed(() => finished(props.run) || props.unavailable);
const status = computed(() => (props.unavailable ? 'unavailable' : props.run?.status ?? 'queued'));
const message = computed(() => (status.value === 'running' || status.value === 'queued') && step.value ? step.value : text.value[status.value]);
const counts = computed(() => props.run?.counts);
</script>

<template>
    <div data-docket-progress :data-status="status" :data-variant="variant">
        <slot v-if="variant === 'stepper' && $slots.steps" name="steps" :items="checklistItems(run)" :run="run" :enabled="succeeded(run)" :finished="over" />
        <template v-else-if="variant === 'stepper'">
            <p class="sr-only" data-docket-message role="status" aria-live="polite">{{ message }}</p>
            <ol data-docket-steps>
                <li v-for="item in list" :key="item.key" data-docket-step :data-status="item.status" :aria-current="item.status === 'active' ? 'step' : undefined">
                    <svg v-if="item.status === 'active'" data-docket-spinner viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="42 100"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite" /></circle></svg>
                    <svg v-else-if="item.status === 'done'" data-docket-check viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    <svg v-else-if="item.status === 'failed'" data-docket-cross viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
                    <span v-else data-docket-dot aria-hidden="true" />
                    <span data-docket-step-label>{{ item.label }}</span>
                    <span v-if="item.status === 'done'" class="sr-only"> (done)</span>
                    <span v-if="item.status === 'failed'" data-docket-step-error role="alert">{{ item.error }}</span>
                </li>
                <li v-if="$slots.actions" data-docket-step data-docket-step-final :data-status="succeeded(run) ? 'done' : over ? 'failed' : 'pending'">
                    <slot name="actions" :run="run" :enabled="succeeded(run)" :finished="over" />
                </li>
            </ol>
        </template>
        <p v-else-if="variant === 'minimal'" data-docket-message role="status" aria-live="polite">
            <svg v-if="!over" data-docket-spinner viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="42 100"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite" /></circle></svg>
            <span data-docket-step-label>{{ message }}</span>
        </p>
        <template v-else>
            <p data-docket-message role="status" aria-live="polite">{{ message }}</p>
            <progress data-docket-bar max="100" :value="value ?? undefined" :aria-label="message">{{ value === null ? '' : `${value}%` }}</progress>
            <p v-if="run?.progress?.total" data-docket-count>{{ run.progress.done.toLocaleString() }} of {{ run.progress.total.toLocaleString() }}</p>
            <p v-if="counts && (counts.failed || counts.skipped)" data-docket-problems>
                <span v-if="counts.failed">{{ counts.failed }} failed</span><span v-if="counts.failed && counts.skipped"> · </span><span v-if="counts.skipped">{{ counts.skipped }} skipped</span>
            </p>
        </template>
        <slot v-if="over" name="final" :run="run" :status="status">
            <button v-if="run?.links.retry && status !== 'succeeded'" type="button" data-docket-retry @click="emit('retry')">{{ text.retry }}</button>
        </slot>
    </div>
</template>
