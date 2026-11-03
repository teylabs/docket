<script setup lang="ts">
import { onMounted, ref, watch, type MaybeRefOrGetter } from 'vue';
import DocketProgress from './DocketProgress.vue';
import { submitDownload, succeeded, type DocketRun } from './run';
import { useDocketRun } from './useDocketRun';

/**
 * A run's progress in a native dialog. When the run succeeds with an output, the dialog shows Download and View and, unless
 * `autoDownload` is off, starts the download at once through `download` (a hidden GET form by default; pass your own form
 * component's submit to reuse it). The page and the dialog stay put; nothing opens a window by itself.
 */
const props = withDefaults(defineProps<{
    run: MaybeRefOrGetter<DocketRun | null | undefined>;
    title: string;
    steps?: Record<string, string>;
    autoDownload?: boolean;
    download?: (url: string) => void;
    labels?: Partial<Record<'download' | 'view' | 'close', string>>;
}>(), { steps: () => ({}), autoDownload: true, download: undefined, labels: () => ({}) });
const open = defineModel<boolean>('open', { default: true });
const emit = defineEmits<{ finished: [run: DocketRun] }>();

const dialog = ref<HTMLDialogElement | null>(null);
const { run, unavailable, retry } = useDocketRun(() => props.run as DocketRun | null | undefined);
const downloaded = ref<string | null>(null);
const text = { download: 'Download', view: 'View', close: 'Close', ...props.labels };

function startDownload() {
    const link = run.value?.links.download;
    if (!link) return;
    (props.download ?? submitDownload)(link);
    downloaded.value = run.value!.id;
}

watch(run, current => {
    if (!current || !succeeded(current)) return;
    emit('finished', current);
    if (props.autoDownload && downloaded.value !== current.id) startDownload();
});
watch(open, value => (value ? dialog.value?.showModal() : dialog.value?.close()));
onMounted(() => open.value && dialog.value?.showModal());
</script>

<template>
    <dialog ref="dialog" data-docket-modal aria-labelledby="docket-modal-title" @close="open = false">
        <h2 id="docket-modal-title" data-docket-modal-title>{{ title }}</h2>
        <DocketProgress :run="run" :steps="steps" :unavailable="unavailable" @retry="retry">
            <template #final="slotProps"><slot name="final" v-bind="slotProps" :retry="retry" /></template>
        </DocketProgress>
        <slot v-if="succeeded(run) && run?.output" name="complete" :run="run" :download="startDownload">
            <div data-docket-output>
                <a :href="run.links.download" data-docket-download @click.prevent="startDownload">{{ text.download }}</a>
                <a v-if="run.links.view" :href="run.links.view" target="_blank" rel="noopener" data-docket-view>{{ text.view }}</a>
            </div>
        </slot>
        <form method="dialog" data-docket-modal-actions><button type="submit" data-docket-close>{{ text.close }}</button></form>
    </dialog>
</template>
