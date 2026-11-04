<script setup lang="ts">
import { computed } from 'vue';
import DocketRunsList from './DocketRunsList.vue';
import type { DocketMode } from './run';
import type { DocketRunFilter } from './runs';
import { useDocketRuns } from './useDocketRuns';

/**
 * The runs in progress for a filter, live: `useDocketRuns()` for the transport and `<DocketRunsList>` for the rows. Every row,
 * state and piece of copy comes from the server. To restyle without forking, use `useDocketRuns()` with your own markup, or
 * pass a `row` slot through.
 */
const props = withDefaults(defineProps<{
    /** Which runs: a run name (the type), optionally a subject type and id, and a row limit. */
    filter: DocketRunFilter;
    title?: string | null;
    /** Render nothing while no run is listed (the default). */
    hideWhenEmpty?: boolean;
    /** Seconds a finished run stays listed; the server caps it at `docket.runs.max_linger_seconds`. */
    linger?: number | null;
    mode?: DocketMode;
    /** The package's route prefix, when `docket.routes.prefix` is not `docket`. */
    prefix?: string;
}>(), { title: null, hideWhenEmpty: true, linger: null, mode: 'polling', prefix: 'docket' });

const { rows, list } = useDocketRuns(computed(() => ({ ...props.filter, linger: props.linger ?? props.filter.linger })), { mode: props.mode, prefix: props.prefix });
</script>

<template>
    <DocketRunsList :rows="rows" :title="title" :empty="list?.empty ?? null" :hide-when-empty="hideWhenEmpty">
        <template v-if="$slots.row" #row="slot"><slot name="row" v-bind="slot" /></template>
    </DocketRunsList>
</template>
