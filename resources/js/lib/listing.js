import { router } from '@inertiajs/vue3';

export const PER_PAGE_OPTIONS = [10, 30, 50, 100];
export const DEFAULT_PER_PAGE = 30;

/**
 * Re-visit the current listing URL with updated query parameters.
 * Other active filters stay in place; the page resets unless one is given.
 */
export function visitListing(changes = {}, { pageName = 'page', keepPage = false } = {}) {
    const url = new URL(window.location.href);
    const params = Object.fromEntries(url.searchParams.entries());

    Object.entries(changes).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            delete params[key];
        } else {
            params[key] = String(value);
        }
    });

    if (!keepPage && !Object.prototype.hasOwnProperty.call(changes, pageName)) {
        delete params[pageName];
    }

    router.get(url.pathname, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

export function currentQuery(key, fallback = '') {
    if (typeof window === 'undefined') {
        return fallback;
    }

    return new URL(window.location.href).searchParams.get(key) ?? fallback;
}
