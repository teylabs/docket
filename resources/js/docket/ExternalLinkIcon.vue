<script setup lang="ts">
/**
 * @internal The end of every link that opens a new tab: the kit's up-right arrow (hidden from screen readers) and a visually
 * hidden "(opens in new tab)". Its name is one the `ui new-tab` rule recognizes as the external icon. Style it through
 * data-docket-new-tab.
 */
</script>

<template>
    <svg data-docket-new-tab viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M7 7h10v10M7 17 17 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg><span class="sr-only"> (opens in new tab)</span>
</template>
