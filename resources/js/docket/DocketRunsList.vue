<script setup lang="ts">
import type { DocketRunRow } from './runs';

/**
 * Renders run rows exactly as the server described them: label, current step, state message, failure or timeout reason, and
 * the server's links. It decides nothing: no permission, status or threshold logic, and no copy of its own beyond what it is
 * given. Hidden when there are no rows and `hideWhenEmpty` is set; otherwise shows `empty`. Unstyled: style it through the
 * data-docket-* hooks (`data-state` and `data-tone` on each row), or replace a row through the `row` slot.
 */
withDefaults(defineProps<{
    rows: DocketRunRow[];
    title?: string | null;
    /** The copy for an empty list (the server sends it as the list's `empty`). */
    empty?: string | null;
    hideWhenEmpty?: boolean;
}>(), { title: null, empty: null, hideWhenEmpty: true });
</script>

<template>
    <section v-if="rows.length > 0 || !hideWhenEmpty" data-docket-runs :aria-label="title ?? undefined">
        <h2 v-if="title" data-docket-runs-title>{{ title }}</h2>
        <ul v-if="rows.length > 0" data-docket-runs-list role="status" aria-live="polite">
            <li v-for="row in rows" :key="row.id" data-docket-run :data-state="row.state" :data-tone="row.tone">
                <slot name="row" :row="row">
                    <svg v-if="row.active" data-docket-spinner viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="42 100"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite" /></circle></svg>
                    <span data-docket-run-label>{{ row.label }}</span>
                    <span v-if="row.step" data-docket-run-step>{{ row.step.label }}</span>
                    <span data-docket-run-message>{{ row.message }}</span>
                    <span v-if="row.reason" data-docket-run-reason>{{ row.reason }}</span>
                    <ul v-if="row.failed_children?.length" data-docket-run-failed-children>
                        <li v-for="child in row.failed_children" :key="child.id" data-docket-run-failed-child :data-required="child.required ? 'true' : 'false'">
                            <span data-docket-run-failed-child-label>{{ child.label }}</span> <span data-docket-run-failed-child-reason>{{ child.reason }}</span>
                        </li>
                    </ul>
                    <a v-if="row.links.output" :href="row.links.output.url" target="_blank" rel="noopener" data-docket-run-output>{{ row.links.output.label }}</a>
                    <a v-if="row.links.help" :href="row.links.help.url" target="_blank" rel="noopener" data-docket-run-help>{{ row.links.help.label }}</a>
                </slot>
            </li>
        </ul>
        <p v-else data-docket-runs-empty>{{ empty }}</p>
    </section>
</template>
