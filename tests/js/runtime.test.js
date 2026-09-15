import { test } from 'node:test';
import assert from 'node:assert/strict';

function fixture(existing) {
    const calls = [];
    const root = {};
    const alpine = { data: () => calls.push('register'), mutateDom: fn => fn(), initTree: node => { assert.equal(node, root); calls.push('init'); }, start: () => calls.push('start') };
    globalThis.fallbackAlpine = alpine;
    globalThis.window = { Alpine: existing ? alpine : undefined, addEventListener() {} };
    globalThis.document = {
        readyState: 'loading',
        getElementById: id => id === 'cms-published-notice' ? null : id === 'cms-bootstrap' ? { textContent: JSON.stringify({ state: {}, fields: {}, alpine: 'data:text/javascript,export default globalThis.fallbackAlpine;' }) } : { content: { firstElementChild: { cloneNode: () => root } } },
        body: { append: node => { assert.equal(node, root); calls.push('append'); } },
    };
    return calls;
}

test('reuses existing Alpine without restarting the host page', async () => {
    const calls = fixture(true);
    const { boot } = await import('../../resources/js/runtime.js');
    await boot();
    await boot();
    assert.deepEqual(calls, ['register', 'append', 'init']);
});

test('loads and starts the fallback when Alpine is absent', async () => {
    const calls = fixture(false);
    const { boot } = await import('../../resources/js/runtime.js');
    await boot();
    assert.deepEqual(calls, ['register', 'append', 'start']);
    assert.equal(window.Alpine, globalThis.fallbackAlpine);
});
