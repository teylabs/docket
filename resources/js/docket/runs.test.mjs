import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { clockSkew, nextLingerDelay, nextListDelay, runsUrl, sameList, visibleRows } from './runs.ts';
import { openEventStream } from './run.ts';

// The same list the PHP suite checks against RunList's real output (tests/Feature/RunListTest.php).
const list = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/runs-list.json', import.meta.url), 'utf8'));

test('the list URL carries the filter under the route prefix, and the stream sits next to it', () => {
    assert.equal(runsUrl({ name: 'report.build' }), '/docket/runs?name=report.build');
    assert.equal(runsUrl({ name: 'report.build', subjectType: 'workspace', subjectId: 7, limit: 5, linger: 10 }, { prefix: '/api/work/', stream: true }),
        '/api/work/runs/stream?name=report.build&subject_type=workspace&subject_id=7&limit=5&linger=10');
    assert.equal(runsUrl({ name: 'x' }, { prefix: '' }), '/runs?name=x');
});

test('a finished row stays until its linger_until on the server clock, and active rows never drop', () => {
    const at = iso => Date.parse(iso);
    assert.deepEqual(visibleRows(list.runs, at('2026-11-05T10:01:00Z')).map(row => row.id), list.runs.map(row => row.id));
    assert.deepEqual(visibleRows(list.runs, at('2026-11-05T10:02:00Z')).map(row => row.state), ['slow', 'timed_out', 'failed']);
    assert.deepEqual(visibleRows(list.runs, at('2026-11-06T00:00:00Z')).map(row => row.state), ['slow', 'timed_out']);

    // This clock runs a minute behind the server's: the done row must still drop at the server's time.
    const skew = clockSkew(list, at('2026-11-05T10:00:00Z'));
    assert.equal(skew, 60_000);
    assert.equal(visibleRows(list.runs, at('2026-11-05T10:01:00Z'), skew).some(row => row.state === 'succeeded'), false);
});

test('polling follows the server cadence and honours Retry-After', () => {
    assert.equal(nextListDelay(list), 2000);
    assert.equal(nextListDelay({ poll_after_ms: 15000 }), 15000);
    assert.equal(nextListDelay(list, 4), 4000);
    assert.equal(nextListDelay(null), 2000);
    assert.equal(nextListDelay({ poll_after_ms: 1 }), 250);
});

class FakeSource {
    static opened = [];
    constructor(url) { this.url = url; this.listeners = {}; this.onerror = null; this.closed = false; FakeSource.opened.push(this); }
    addEventListener(type, listener) { (this.listeners[type] ??= []).push(listener); }
    emit(type, data) { for (const listener of this.listeners[type] ?? []) listener({ data: typeof data === 'string' ? data : JSON.stringify(data) }); }
    close() { this.closed = true; }
}

test('the list stream uses the run transport: runs events in, reconnect on timeout, fall back once on error', () => {
    FakeSource.opened = [];
    const seen = { lists: [], fallbacks: 0 };
    openEventStream('/docket/runs/stream?name=report.build', 'runs', { message: l => seen.lists.push(l), fallback: () => seen.fallbacks++ }, FakeSource);
    FakeSource.opened[0].emit('runs', list);
    FakeSource.opened[0].emit('end', { reason: 'timeout' });
    assert.equal(FakeSource.opened.length, 2);
    assert.equal(FakeSource.opened[0].closed, true);
    FakeSource.opened[1].onerror();
    FakeSource.opened[1].onerror();
    assert.deepEqual(seen.lists, [list]);
    assert.equal(seen.fallbacks, 1);
    assert.equal(openEventStream('/x', 'runs', { message() {}, fallback() {} }, undefined), null);
});

const lingering = (id, lingerUntil) => ({ id, state: lingerUntil ? 'succeeded' : 'running', linger_until: lingerUntil });
const answer = (runs, active = false) => ({ schema_version: 1, runs, active, empty: 'Nothing yet', poll_after_ms: active ? 2000 : 15000, generated_at: '2026-01-01T00:00:00Z' });
const start = Date.parse('2026-01-01T00:00:00Z');

test('an idle list with no lingering row needs no clock', () => {
    assert.equal(nextLingerDelay([], start), null);
    assert.equal(nextLingerDelay([lingering(1, null)], start), null);
    assert.equal(nextLingerDelay([lingering(1, '2025-12-31T23:59:00Z')], start), null);
});

test('the clock wakes once, when the soonest lingering row drops off', () => {
    const rows = [lingering(1, '2026-01-01T00:00:30Z'), lingering(2, '2026-01-01T00:00:10Z'), lingering(3, null)];
    const delay = nextLingerDelay(rows, start);
    assert.ok(delay >= 10_000 && delay < 11_000, `delay ${delay}`);
    assert.deepEqual(visibleRows(rows, start + delay).map(row => row.id), [1, 3]);
});

test('the linger delay reads server time through the skew', () => {
    assert.ok(nextLingerDelay([lingering(1, '2026-01-01T00:00:10Z')], start, 5_000) < 6_000);
});

test('an unchanged idle answer counts as the same list, a new run does not', () => {
    assert.equal(sameList(answer([]), { ...answer([]), generated_at: '2026-01-01T00:00:15Z' }), true);
    assert.equal(sameList(answer([]), answer([lingering(9, null)], true)), false);
    assert.equal(sameList(answer([]), { ...answer([]), poll_after_ms: 2000 }), false);
    assert.equal(sameList(null, answer([])), false);
});
