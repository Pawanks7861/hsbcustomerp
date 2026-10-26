import { defineAsyncComponent, defineComponent, h, shallowRef } from 'vue';

const LoadingSkeleton = defineComponent({
    name: 'LazyLoadingSkeleton',
    setup() {
        return () => h('div', { class: 'min-h-24 animate-pulse rounded-xl bg-slate-100', 'aria-busy': 'true' });
    },
});

const Nothing = defineComponent({ name: 'LazyNothing', setup: () => () => null });

function failedPanel(label, onRetry) {
    return h('div', { class: 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950', role: 'alert' }, [
        `Could not load ${label}. Your other work on this page is unchanged. `,
        h('button', { type: 'button', class: 'font-medium underline', onClick: onRetry }, 'Retry'),
        h('span', { class: 'ml-1 text-amber-800' }, ' If it keeps failing, save your work and reload the page.'),
    ]);
}

/**
 * Load a component only when it is first rendered.
 *
 * - The browser keeps the downloaded module, so reopening never refetches it.
 * - A skeleton appears only if loading takes longer than `delay` ms.
 * - A failed download is retried once automatically, then shows a retry control
 *   (or renders nothing when `silent`). The surrounding page and its form state
 *   are left untouched.
 */
export function lazyComponent(loader, { label = 'this section', delay = 200, timeout = 20000, skeleton = true, silent = false } = {}) {
    return defineComponent({
        name: 'LazyComponent',
        inheritAttrs: false,
        setup(_, { attrs, slots }) {
            const make = () => defineAsyncComponent({
                loader,
                delay,
                timeout,
                loadingComponent: skeleton ? LoadingSkeleton : undefined,
                errorComponent: silent ? Nothing : defineComponent({
                    name: 'LazyLoadFailed',
                    setup: () => () => failedPanel(label, () => {
                        inner.value = make();
                    }),
                }),
                onError(error, retry, fail, attempts) {
                    if (attempts <= 1) {
                        retry();

                        return;
                    }
                    fail();
                },
            });

            const inner = shallowRef(make());

            return () => h(inner.value, attrs, slots);
        },
    });
}

/**
 * Run once the browser is idle, so non-essential widgets do not compete with
 * the page's own first render.
 */
export function whenIdle(callback, timeout = 2000) {
    if (typeof window === 'undefined') {
        return () => {};
    }

    if ('requestIdleCallback' in window) {
        const id = window.requestIdleCallback(callback, { timeout });

        return () => window.cancelIdleCallback(id);
    }

    const id = window.setTimeout(callback, 600);

    return () => window.clearTimeout(id);
}
