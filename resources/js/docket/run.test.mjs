import test from 'node:test';
import assert from 'node:assert/strict';
import { finished, formFields, newer, nextDelay, percent } from './run.ts';

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
