import { createInertiaApp } from '@inertiajs/react';
import { GlobalStyles } from '@bigcommerce/big-design';
import { theme } from '@bigcommerce/big-design-theme';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from 'styled-components';
import type { ComponentType } from 'react';
import '../css/app.css';
import { configureAppToken } from './http';
import type { SharedProps } from './types';

createInertiaApp<SharedProps>({
    title: (title) => (title ? `${title} · Tamara` : 'Tamara'),
    resolve: async (name): Promise<ComponentType> => {
        const pages = import.meta.glob('./Pages/**/*.tsx');
        const loader = pages[`./Pages/${name}.tsx`];
        if (!loader) throw new Error(`Unknown Inertia page: ${name}`);
        const page = await loader() as { default: ComponentType };
        return page.default;
    },
    setup({ el, App, props }) {
        configureAppToken((props.initialPage.props as SharedProps).appToken);

        createRoot(el).render(
            <ThemeProvider theme={theme}>
                <GlobalStyles />
                <App {...props} />
            </ThemeProvider>,
        );
    },
    progress: { color: '#5f259f' },
});
