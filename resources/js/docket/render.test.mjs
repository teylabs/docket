import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { registerHooks, stripTypeScriptTypes } from 'node:module';
import { fileURLToPath } from 'node:url';
import { parse, compileScript } from 'vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

// Compile the published .vue files on import, and let their extensionless imports reach the .ts sources, so the tests render
// exactly the files an application publishes.
registerHooks({
    resolve(specifier, context, next) {
        if (specifier.startsWith('.') && !/\.\w+$/.test(specifier)) {
            const candidate = new URL(specifier + '.ts', context.parentURL);
            if (existsSync(fileURLToPath(candidate))) return next(candidate.href, context);
        }
        return next(specifier, context);
    },
    load(url, context, next) {
        if (!url.endsWith('.vue')) return next(url, context);
        const filename = fileURLToPath(url);
        const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
        const script = compileScript(descriptor, { id: filename, inlineTemplate: true, fs: { fileExists: existsSync, readFile: file => readFileSync(file, 'utf8') } });
        return { format: 'module', source: stripTypeScriptTypes(script.content), shortCircuit: true };
    },
});

const { default: DocketProgress } = await import('./DocketProgress.vue');
const { default: DocketRunModal } = await import('./DocketRunModal.vue');

const run = (overrides = {}) => ({
    schema_version: 1, id: 'r1', token: 't', name: 'report.build', status: 'running', revision: 3, step: 'render', poll_after_ms: 2000,
    progress: { done: 1, total: null, percent: null }, counts: { processed: 0, succeeded: 0, failed: 0, skipped: 0 }, links: {},
    steps: [
        { key: 'prepare', label: 'Preparing data', status: 'done' },
        { key: 'render', label: 'Rendering PDF', status: 'active' },
        { key: 'save', label: 'Saving', status: 'pending' },
    ],
    ...overrides,
});

// SSR fragment and v-if markers (<!--[-->, <!--v-if-->) are not markup; drop them so assertions read the elements.
const render = async (component, props, slots = {}) => (await renderToString(createSSRApp({ render: () => h(component, props, slots) }))).replace(/<!--[\s\S]*?-->/g, '');
const attr = (html, step) => html.match(new RegExp(`<li[^>]*data-status="(\\w+)"[^>]*>(?:(?!</li>).)*${step}`, 's'))?.[1];

test('bar is the default and keeps the existing markup', async () => {
    const html = await render(DocketProgress, { run: run() });
    assert.match(html, /data-variant="bar"/);
    assert.match(html, /<progress data-docket-bar/);
    assert.match(html, /Rendering PDF/);
    assert.doesNotMatch(html, /data-docket-steps/);
});

test('stepper lists every step: done with a check, active with a spinner, pending muted, and a final actions step', async () => {
    const html = await render(DocketProgress, { run: run(), variant: 'stepper' }, { actions: ({ enabled }) => h('button', { disabled: !enabled }, 'View') });
    assert.equal((html.match(/<li data-docket-step/g) ?? []).length, 4);
    assert.equal(attr(html, 'Preparing data'), 'done');
    assert.equal(attr(html, 'Rendering PDF'), 'active');
    assert.equal(attr(html, 'Saving'), 'pending');
    assert.match(html, /data-docket-check[\s\S]*Preparing data/);
    assert.match(html, /data-docket-spinner[\s\S]*<animateTransform[\s\S]*Rendering PDF/);
    assert.match(html, /aria-current="step"/);
    assert.match(html, /data-docket-step-final data-status="pending"><button disabled>View<\/button>/);
    assert.doesNotMatch(html, /<progress/);
});

test('stepper shows a failed step with its error inline, and enables the actions once the run succeeds', async () => {
    const failed = await render(DocketProgress, { run: run({ status: 'failed', steps: [{ key: 'prepare', label: 'Preparing data', status: 'done' }, { key: 'render', label: 'Rendering PDF', status: 'failed', error: 'report.render_failed' }, { key: 'save', label: 'Saving', status: 'pending' }] }), variant: 'stepper' });
    assert.equal(attr(failed, 'Rendering PDF'), 'failed');
    assert.match(failed, /data-docket-cross[\s\S]*Rendering PDF[\s\S]*data-docket-step-error role="alert">report.render_failed/);
    assert.doesNotMatch(failed, /data-docket-spinner/);

    const done = await render(DocketProgress, { run: run({ status: 'succeeded', steps: run().steps.map(step => ({ ...step, status: 'done' })) }), variant: 'stepper' }, { actions: ({ enabled }) => h('button', { disabled: !enabled }, 'View') });
    assert.match(done, /data-docket-step-final data-status="done"><button>View<\/button>/);
});

test('stepper uses the caller\'s labels for step keys', async () => {
    const html = await render(DocketProgress, { run: run(), variant: 'stepper', steps: { render: 'Drawing the report' } });
    assert.match(html, /Drawing the report/);
    assert.doesNotMatch(html, /Rendering PDF/);
});

test('minimal shows a spinner and the current step only, and drops the spinner once finished', async () => {
    const html = await render(DocketProgress, { run: run(), variant: 'minimal' });
    assert.match(html, /data-docket-spinner[\s\S]*Rendering PDF/);
    assert.doesNotMatch(html, /Preparing data|Saving|<progress|data-docket-steps/);

    const done = await render(DocketProgress, { run: run({ status: 'succeeded', steps: [] }), variant: 'minimal' });
    assert.doesNotMatch(done, /data-docket-spinner/);
    assert.match(done, /Done/);
});

test('the modal passes the variant through and puts Download and View in the stepper\'s last step', async () => {
    const running = await render(DocketRunModal, { run: run(), title: 'Building the report', variant: 'stepper', open: false });
    assert.match(running, /data-docket-steps/);
    assert.match(running, /data-docket-step-final[^>]*><div data-docket-output><button type="button" disabled data-docket-download>Download<\/button><button type="button" disabled data-docket-view>View<\/button>/);

    const succeeded = await render(DocketRunModal, { run: run({ status: 'succeeded', output: { name: 'r.pdf', mime: 'application/pdf', size: 3 }, links: { download: '/d', view: '/v' }, steps: run().steps.map(step => ({ ...step, status: 'done' })) }), title: 'Building the report', variant: 'stepper', open: false, autoDownload: false });
    assert.match(succeeded, /<button type="button" data-docket-download>Download<\/button><a href="\/v" target="_blank" rel="noopener" data-docket-view>View<\/a>/);
    assert.equal((succeeded.match(/data-docket-output/g) ?? []).length, 1);
});

const { default: DocketRunsList } = await import('./DocketRunsList.vue');
const runsList = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/runs-list.json', import.meta.url), 'utf8'));

test('the runs list renders each row\'s server copy, state and links as given', async () => {
    const html = await render(DocketRunsList, { rows: runsList.runs, title: 'Reports in progress' });
    const rows = html.match(/<li data-docket-run[\s\S]*?<\/li>/g);
    assert.equal(rows.length, 4);
    assert.match(html, /<h2 data-docket-runs-title>Reports in progress<\/h2>/);
    assert.match(rows[0], /data-state="slow" data-tone="warning"[\s\S]*data-docket-spinner[\s\S]*Monthly report[\s\S]*data-docket-run-step>Rendering PDF[\s\S]*data-docket-run-message>Taking longer than usual/);
    assert.match(rows[1], /data-state="timed_out" data-tone="danger"[\s\S]*Timed out[\s\S]*data-docket-run-reason>This is taking much longer than expected. It may have stopped.[\s\S]*<a href="https:\/\/example.test\/help" target="_blank" rel="noopener" data-docket-run-help>Get help<\/a>/);
    assert.match(rows[2], /data-state="failed"[\s\S]*Something went wrong[\s\S]*It stopped responding./);
    assert.doesNotMatch(rows[2], /data-docket-spinner/);
    assert.match(rows[3], /data-state="succeeded"[\s\S]*Done[\s\S]*<a href="\/docket\/runs\/a4\/output\/view\?expires=1&amp;signature=s" target="_blank" rel="noopener" data-docket-run-output>Open<\/a>/);
});

test('the runs list renders the server\'s copy even when it differs from the defaults', async () => {
    const row = { ...runsList.runs[0], message: 'Still crunching', label: 'Quarterly export' };
    const html = await render(DocketRunsList, { rows: [row] });
    assert.match(html, /Quarterly export[\s\S]*Still crunching/);
    assert.doesNotMatch(html, /Taking longer than usual/);
});

test('an empty runs list is hidden by default, or shows the server\'s empty copy', async () => {
    assert.equal(await render(DocketRunsList, { rows: [] }), '');
    assert.match(await render(DocketRunsList, { rows: [], hideWhenEmpty: false, empty: 'Nothing running.' }), /data-docket-runs-empty>Nothing running.<\/p>/);
});

test('the widget compiles, and renders nothing on the server until the browser reads the list', async () => {
    const { default: DocketRunsWidget } = await import('./DocketRunsWidget.vue');
    assert.equal(await render(DocketRunsWidget, { filter: { name: 'report.build' }, title: 'Reports' }), '');
    assert.match(await render(DocketRunsWidget, { filter: { name: 'report.build' }, hideWhenEmpty: false }), /data-docket-runs-empty/);
});

test('a succeeded run that is still settling keeps its last step active and shows no actions state yet', async () => {
    const settling = run({ status: 'succeeded', settling: true, poll_after_ms: 2000, steps: [{ key: 'prepare', label: 'Preparing data', status: 'done' }, { key: 'render', label: 'Rendering PDF', status: 'done' }, { key: 'save', label: 'Saving', status: 'active' }] });
    const html = await render(DocketProgress, { run: settling, variant: 'stepper' }, { actions: ({ enabled, finished }) => h('button', { disabled: !enabled, 'data-finished': String(finished) }, 'View') });
    assert.equal(attr(html, 'Saving'), 'active');
    assert.match(html, /data-docket-spinner[\s\S]*Saving/);
    assert.match(html, /data-docket-step-final data-status="pending"><button disabled data-finished="false">View<\/button>/);
    assert.doesNotMatch(html, /data-docket-retry/);
});

test('the stepper hands the server\'s checklist items to a steps slot, so an app can draw them with its own checklist', async () => {
    const checklist = [
        { key: 'prepare', label: 'Preparing data', state: 'done', detail: null, reason: null, required: true, action: null },
        { key: 'render', label: 'Rendering PDF', state: 'failed', detail: null, reason: 'The renderer timed out.', required: true, action: null },
    ];
    const seen = [];
    const html = await render(DocketProgress, { run: run({ status: 'failed', checklist }), variant: 'stepper' }, { steps: props => (seen.push(props), h('ol', { 'data-host-checklist': '' }, props.items.map(item => h('li', { 'data-state': item.state }, `${item.label} ${item.reason ?? ''}`)))) });
    assert.deepEqual(seen[0].items, checklist);
    assert.equal(seen[0].enabled, false);
    assert.equal(seen[0].finished, true);
    assert.match(html, /data-host-checklist[\s\S]*data-state="failed">Rendering PDF The renderer timed out./);
    assert.doesNotMatch(html, /data-docket-steps/);
});

test('a partial row lists its failed children with the server\'s reasons', async () => {
    const row = { ...runsList.runs[3], state: 'partial', status: 'partial', message: 'Finished with some problems', failed_children: [{ id: 'c1', name: 'reading', label: 'Reading the message', required: false, code: 'mail.unreadable', reason: 'The message could not be read.' }] };
    const html = await render(DocketRunsList, { rows: [row] });
    assert.match(html, /data-docket-run-failed-child data-required="false"><span data-docket-run-failed-child-label>Reading the message<\/span> <span data-docket-run-failed-child-reason>The message could not be read.<\/span>/);
    assert.doesNotMatch(html, /mail\.unreadable/);
});
