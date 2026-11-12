import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import i18n, {
    applyServerLocale,
    initializeLocale,
    loadLocaleMessages,
} from './i18n';
import {
    applyRegionalProp,
} from './lib/formatSettings';
import {
    applyActiveOrganization,
    withOrganizationHeader,
} from './lib/activeOrganization';
import {
    installPluginRuntime,
    loadPluginAssets,
    resolveRuntimePage,
} from './plugins/runtime';

const appName =
    document.querySelector('meta[name="application-name"]')?.content ||
    'Inventoros';

// window.Inventoros: Vue plus the plugin SDK, for pre-built plugin bundles
// that are imported at runtime (see ./plugins/runtime.js).
installPluginRuntime();

// Main application pages.
//
// IMPORTANT:
// Use eager + default import so Vite creates a real page-component map
// instead of an empty lazy import map in the production bundle.
const pages = import.meta.glob('./Pages/**/*.vue', {
    eager: true,
    import: 'default',
});

// Plugin pages remain lazy because plugin pages are loaded independently.
const pluginPages = import.meta.glob(
    '../../plugins/*/resources/js/Pages/**/*.vue'
);

createInertiaApp({
    title: (title) =>
        title ? `${title} - ${appName}` : appName,

    resolve: async (name, page) => {
        // Keep the current page usable while a newly selected/server-resolved
        // catalog downloads. The navigation then renders in one language.
        await loadLocaleMessages(page?.props?.locale).catch(() => {});

        // ---------------------------------------------------------
        // PLUGIN PAGE
        // ---------------------------------------------------------
        // Format:
        // Plugin::PluginName/PagePath
        //
        // Example:
        // Plugin::SomePlugin/Dashboard
        // ---------------------------------------------------------
        if (name.startsWith('Plugin::')) {
            // First check for a page registered by a runtime plugin bundle.
            const runtimePage = await resolveRuntimePage(name, page);

            if (runtimePage) {
                return runtimePage;
            }

            const [, pluginPath] = name.split('::');

            const [pluginName, ...pagePath] =
                pluginPath.split('/');

            const pageName = pagePath.join('/');

            const pluginPagePath =
                `../../plugins/${pluginName}/resources/js/Pages/${pageName}.vue`;

            if (pluginPages[pluginPagePath]) {
                return pluginPages[pluginPagePath]();
            }

            console.warn(
                `Plugin page not found: ${pluginPagePath}, falling back to main pages`
            );
        }

        // ---------------------------------------------------------
        // MAIN APPLICATION PAGE
        // ---------------------------------------------------------
        const pagePath = `./Pages/${name}.vue`;

        const pageComponent = pages[pagePath];

        if (pageComponent) {
            return pageComponent;
        }

        console.error('Inertia page resolution failed:', {
            name,
            pagePath,
            availablePages: Object.keys(pages).slice(0, 20),
            totalPages: Object.keys(pages).length,
        });

        throw new Error(`Page not found: ${pagePath}`);
    },

    async setup({ el, App, props, plugin }) {
        // Keep vue-i18n on the locale the server resolved, including after a
        // visit that changed it (saving the language preference).
        await initializeLocale(
            props.initialPage?.props?.locale
        );

        router.on('navigate', (event) => {
            applyServerLocale(
                event.detail.page.props.locale
            ).catch(() => {});
        });

        // Money and dates follow the organization's regional settings.
        applyRegionalProp(
            props.initialPage?.props?.regional
        );

        router.on('navigate', (event) => {
            applyRegionalProp(
                event.detail.page.props.regional
            );
        });

        // Send the organization each page was rendered for with every
        // request, so a stale tab cannot write into another organization.
        applyActiveOrganization(
            props.initialPage?.props?.auth
        );

        router.on('navigate', (event) => {
            applyActiveOrganization(
                event.detail.page.props.auth
            );
        });

        router.on('before', (event) => {
            event.detail.visit.headers =
                withOrganizationHeader(
                    event.detail.visit.headers
                );
        });

        // Import the UI bundles of active plugins, and any newly activated one.
        loadPluginAssets(
            props.initialPage?.props?.pluginAssets
        );

        router.on('navigate', (event) => {
            loadPluginAssets(
                event.detail.page.props.pluginAssets
            );
        });

        return createApp({
            render: () => h(App, props),
        })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },

    progress: {
        color: '#4B5563',
    },
});