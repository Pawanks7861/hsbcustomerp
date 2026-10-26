import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = import.meta.glob('./Pages/**/*.vue');
const RELOAD_KEY = 'erp.chunk-reload';

/**
 * Pages are separate chunks fetched on first visit. After a redeploy the old
 * chunk names no longer exist, so reload once to pick up the new manifest.
 */
async function resolvePage(name) {
    try {
        const page = await resolvePageComponent(`./Pages/${name}.vue`, pages);
        sessionStorage.removeItem(RELOAD_KEY);

        return page;
    } catch (error) {
        if (sessionStorage.getItem(RELOAD_KEY) !== name) {
            sessionStorage.setItem(RELOAD_KEY, name);
            window.location.reload();

            return new Promise(() => {});
        }

        throw error;
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: resolvePage,
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
        delay: 250,
    },
});
