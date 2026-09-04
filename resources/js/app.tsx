import { createInertiaApp, router } from '@inertiajs/react';
import { GlobalStyles } from '@bigcommerce/big-design';
import { theme } from '@bigcommerce/big-design-theme';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from 'styled-components';
import type { ComponentType } from 'react';
import '../css/app.css';
import { authorizationHeaders, configureAppToken } from './http';
import type { SharedProps } from './types';

// Only initialize Inertia when the Inertia root/data is present on the page.
// Some non-Inertia Blade views (e.g. the default welcome page) may still load our
// JS bundle during development — guard to avoid calling Inertia with no page.
if (typeof document !== 'undefined' && (document.getElementById('app') || document.querySelector('[data-page]'))) {
    router.on('before', (event) => {
        event.detail.visit.options.headers = {
            ...event.detail.visit.options.headers,
            ...authorizationHeaders(),
        };
    });

    createInertiaApp<SharedProps>({
    title: (title) => (title ? `${title} · Tamara` : 'Tamara'),
    resolve: async (name): Promise<ComponentType> => {
        const pages = import.meta.glob('./Pages/**/*.{tsx,ts,jsx,js}');
        // try exact path first, then fall back to searching keys for a match
        let loader = pages[`./Pages/${name}.tsx`] ?? pages[`./Pages/${name}.jsx`] ?? pages[`./Pages/${name}.ts`] ?? pages[`./Pages/${name}.js`];
        if (!loader) {
            const key = Object.keys(pages).find((k) =>
                k.endsWith(`/${name}.tsx`) || k.endsWith(`/${name}.jsx`) || k.endsWith(`/${name}.ts`) || k.endsWith(`/${name}.js`),
            );
            if (key) loader = pages[key];
        }
        if (!loader) {
            throw new Error(`Unknown Inertia page: ${name}. Available pages: ${Object.keys(pages).join(', ')}`);
        }
        const page = (await loader()) as { default?: ComponentType };
        if (!page || !page.default) {
            throw new Error(`Inertia page module for "${name}" did not export a default React component.`);
        }
        return page.default;
    },
    setup({ el, App, props }) {
        // Be defensive: initialPage or its props may be absent in some edge cases
        const initialProps = (props.initialPage && (props.initialPage.props as SharedProps)) ?? (props as unknown as SharedProps);
        configureAppToken(initialProps?.appToken);

        if (!el) {
            // If el is missing, fail fast with a helpful error
            throw new Error('Inertia root element not found. Ensure @inertia is present in the root Blade view.');
        }

        createRoot(el).render(
            <ThemeProvider theme={theme}>
                <GlobalStyles />
                <App {...props} />
            </ThemeProvider>,
        );
    },
    progress: { color: '#5f259f' },
    });
} else {
    // No Inertia root found on the page; skip Inertia boot to avoid runtime errors.
    // This allows the same bundle to be loaded on non-Inertia pages (like welcome.blade.php).
    // eslint-disable-next-line no-console
    console.debug('Inertia root not found — skipping createInertiaApp initialization.');
}

