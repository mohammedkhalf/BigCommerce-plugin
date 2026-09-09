import { Badge, Button, H2, Text } from '@bigcommerce/big-design';
import { router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { authorizationHeaders } from '../http';
import type { SharedProps } from '../types';

const navigation = [
    ['Dashboard', '/dashboard'],
    ['Payments', '/payments'],
    ['Settings', '/settings'],
    ['Help', '/help'],
] as const;

export default function AppLayout({ children }: PropsWithChildren) {
    const page = usePage<SharedProps>();
    const path = typeof window === 'undefined' ? '' : window.location.pathname;
    const store = page.props.store;
    const environment = store?.environment ?? 'sandbox';

    const visit = (url: string) => {
        router.visit(url, { headers: authorizationHeaders(), preserveState: true });
    };

    return (
        <div className="app-shell">
            <header className="topbar">
                <button className="brand" onClick={() => visit('/dashboard')} type="button" aria-label="Tamara dashboard">
                    <span>
                        <img
                            src="/images/logo.png"
                            alt="Logo"
                            width={100}
                        />
                        <small>Buy now, pay later</small>
                    </span>
                </button>
                <div className="store-meta">
                    <div>
                        <Text margin="none">{store?.name ?? (store?.hash ? `Store ${store.hash}` : 'BigCommerce store')}</Text>
                        <small>Merchant dashboard</small>
                    </div>
                    <Badge label={environment === 'live' ? 'Live' : 'Sandbox'} variant={environment === 'live' ? 'success' : 'warning'} />
                </div>
            </header>
            <div className="app-frame">
                <aside className="sidebar">
                    <nav aria-label="Main navigation">
                        {navigation.map(([label, href]) => (
                            <button
                                className={path.startsWith(href) ? 'nav-item active' : 'nav-item'}
                                key={href}
                                onClick={() => visit(href)}
                                type="button"
                            >
                                <span className="nav-dot" />
                                {label}
                            </button>
                        ))}
                    </nav>
                    <div className="sidebar-support">
                        <H2 marginBottom="xSmall">Need a hand?</H2>
                        <Text color="secondary" marginBottom="medium">Find integration guidance and support resources.</Text>
                        <Button mobileWidth="100%" onClick={() => visit('/help')} variant="secondary">Get help</Button>
                    </div>
                </aside>
                <main className="main-content">{children}</main>
            </div>
        </div>
    );
}
