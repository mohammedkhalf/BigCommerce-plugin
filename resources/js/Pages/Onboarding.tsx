import { Button, H1, H3, Panel, Text } from '@bigcommerce/big-design';
import { Head, router } from '@inertiajs/react';
import SetupChecklist from '../Components/SetupChecklist';
import { authorizationHeaders } from '../http';
import AppLayout from '../Layouts/AppLayout';

export default function Onboarding() {
    const openSettings = () => router.visit('/settings', { headers: authorizationHeaders() });

    return (
        <AppLayout>
            <Head title="Welcome" />
            <section className="onboarding-hero">
                <div className="hero-copy">
                    <span className="eyebrow">TAMARA FOR BIGCOMMERCE</span>
                    <H1>Give your customers more ways to pay</H1>
                    <Text color="secondary">
                        Connect your Tamara merchant account, configure checkout, and run a test order before going live.
                    </Text>
                    <div className="hero-actions">
                        <Button onClick={openSettings}>Start setup</Button>
                        <Button onClick={() => router.visit('/help', { headers: authorizationHeaders() })} variant="secondary">View setup guide</Button>
                    </div>
                </div>
                <div className="hero-visual" aria-label="Tamara payment preview">
                    <div className="checkout-preview">
                        <div className="preview-bar"><span /><span /><span /></div>
                        <H3>Pay with Tamara</H3>
                        <Text color="secondary">Split your purchase into flexible payments.</Text>
                        <div className="payment-lines"><span /><span /><span /></div>
                        <div className="preview-button">Continue with Tamara</div>
                    </div>
                </div>
            </section>
            <div className="onboarding-grid">
                <SetupChecklist />
                <Panel>
                    <H3 marginBottom="small">Before you begin</H3>
                    <ul className="plain-list">
                        <li>A Tamara merchant account</li>
                        <li>Sandbox API credentials</li>
                        <li>Access to BigCommerce payment settings</li>
                    </ul>
                    <Text color="secondary" marginTop="large">Start in sandbox and switch to live only after completing a successful test order.</Text>
                </Panel>
            </div>
        </AppLayout>
    );
}
