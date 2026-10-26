import test from 'node:test';
import assert from 'node:assert/strict';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { lazyComponent, whenIdle } from '../../resources/js/lib/lazyComponent.js';

const Report = defineComponent({
    props: { title: { type: String, default: '' } },
    setup: (props) => () => h('section', `Report: ${props.title}`),
});

async function render(component, props = {}) {
    return renderToString(createSSRApp({ render: () => h(component, props) }));
}

test('loads the component on first render and passes props through', async () => {
    let calls = 0;
    const Lazy = lazyComponent(() => {
        calls++;

        return Promise.resolve({ __esModule: true, default: Report });
    });

    const html = await render(Lazy, { title: 'Stock' });

    assert.match(html, /Report: Stock/);
    assert.equal(calls, 1);
});

test('retries a failed download once before giving up', async () => {
    let calls = 0;
    const Lazy = lazyComponent(() => {
        calls++;

        return calls === 1 ? Promise.reject(new Error('Failed to fetch dynamically imported module')) : Promise.resolve({ __esModule: true, default: Report });
    });

    const html = await render(Lazy, { title: 'Ledger' });

    assert.match(html, /Report: Ledger/);
    assert.equal(calls, 2);
});

test('a component that keeps failing shows a retry message instead of a blank area', async () => {
    const Lazy = lazyComponent(() => Promise.reject(new Error('chunk missing')), { label: 'the report viewer' });

    const html = await render(Lazy);

    assert.match(html, /Could not load the report viewer/);
    assert.match(html, /Retry/);
});

test('silent components render nothing when they cannot load', async () => {
    const Lazy = lazyComponent(() => Promise.reject(new Error('chunk missing')), { silent: true });

    const html = await render(Lazy);

    assert.doesNotMatch(html, /Could not load/);
});

test('whenIdle is a safe no-op outside the browser', () => {
    const cancel = whenIdle(() => {
        throw new Error('should not run');
    });

    assert.equal(typeof cancel, 'function');
    cancel();
});
