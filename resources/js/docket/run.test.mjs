import test from 'node:test';
import assert from 'node:assert/strict';
import { ensureDownloadFrame, claimAutoDownload, finished, formFields, freshDownloadLink, newer, nextDelay, percent, submitDownload } from './run.ts';

const run = (overrides = {}) => ({ schema_version: 1, id: 'r1', token: 't', name: 'build', status: 'running', revision: 1, poll_after_ms: 2000, links: {}, ...overrides });

test('a bar is determinate only when the total is known, and full once finished', () => {
    assert.equal(percent(run({ progress: { done: 3, total: 4, percent: 75 } })), 75);
    assert.equal(percent(run({ progress: { done: 3, total: null, percent: null } })), null);
    assert.equal(percent(run({ status: 'succeeded', progress: { done: 3, total: null, percent: null } })), 100);
    assert.equal(percent(run({ progress: { done: 9, total: 4, percent: null } })), 100);
});

test('finished covers every final status', () => {
    assert.deepEqual(['queued', 'running', 'succeeded', 'partial', 'failed', 'cancelled'].map(status => finished({ status })), [false, false, true, true, true, true]);
});

test('polling follows the run hint and honours Retry-After', () => {
    assert.equal(nextDelay(run()), 2000);
    assert.equal(nextDelay(run(), 3), 3000);
    assert.equal(nextDelay(run({ poll_after_ms: 10 })), 250);
});

test('a late response never replaces a newer snapshot', () => {
    assert.equal(newer(run({ revision: 4 }), run({ revision: 3 })), false);
    assert.equal(newer(run({ revision: 4 }), run({ revision: 5 })), true);
    assert.equal(newer(null, run()), true);
});

test('a signed URL becomes a GET form with its query string as hidden fields', () => {
    assert.deepEqual(formFields('https://app.test/docket/runs/abc/output?expires=1700&signature=f00'), {
        action: 'https://app.test/docket/runs/abc/output', fields: [['expires', '1700'], ['signature', 'f00']],
    });
    assert.deepEqual(formFields('/docket/runs/abc/output?signature=x'), { action: '/docket/runs/abc/output', fields: [['signature', 'x']] });
});

test('a download reads a freshly signed link first, and never navigates to a known-dead one', async () => {
    const held = run({ status: 'succeeded', links: { poll: '/docket/runs/t', download: '/old?signature=1' } });
    const answer = (status, body = {}) => async () => ({ ok: status === 200, status, json: async () => body });

    assert.equal(await freshDownloadLink(held, answer(200, run({ links: { download: '/new?signature=2' } }))), '/new?signature=2');
    assert.equal(await freshDownloadLink(held, answer(404)), null);
    assert.equal(await freshDownloadLink(held, answer(429)), '/old?signature=1');
    assert.equal(await freshDownloadLink(held, async () => { throw new Error('offline'); }), '/old?signature=1');
});

test('the default download goes through a hidden frame, so an error page never replaces the page', () => {
    const made = [];
    const element = tag => {
        const node = { tag, children: [], append(child) { this.children.push(child); }, submit() { made.push(['submit', this.target, this.action, this.children.map(c => [c.name, c.value])]); }, remove() {} };
        made.push(node);
        return node;
    };
    let frames = 0;
    const doc = { createElement: element, body: { append() {} }, querySelector: () => (frames++ ? {} : null) };

    submitDownload('/docket/runs/t/output?expires=9&signature=s', doc);
    submitDownload('/docket/runs/t/output?expires=9&signature=s', doc);
    assert.equal(made.filter(node => node.tag === 'iframe').length, 1);
    assert.equal(made.find(node => node.tag === 'iframe').name, 'docket-download');
    assert.deepEqual(made.filter(entry => entry[0] === 'submit')[0], ['submit', 'docket-download', '/docket/runs/t/output', [['expires', '9'], ['signature', 's']]]);
});

test('the automatic download starts once per run on a page, even if the dialog is opened again', () => {
    assert.equal(claimAutoDownload('run-a'), true);
    assert.equal(claimAutoDownload('run-a'), false);
    assert.equal(claimAutoDownload('run-b'), true);
});

test('ensureDownloadFrame creates the frame once and always returns its name', () => {
    const frames = [];
    const doc = {
        createElement: () => ({}),
        body: { append: node => frames.push(node) },
        querySelector: selector => (selector === 'iframe[name="docket-download"]' ? frames.find(frame => frame.name === 'docket-download') ?? null : null),
    };
    assert.equal(ensureDownloadFrame(doc), 'docket-download');
    assert.equal(ensureDownloadFrame(doc), 'docket-download');
    assert.equal(frames.length, 1);
    assert.equal(frames[0].hidden, true);
});
