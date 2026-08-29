import { Button, H3, Panel, Text } from '@bigcommerce/big-design';
import { Head } from '@inertiajs/react';
import PageHeader from '../Components/PageHeader';
import AppLayout from '../Layouts/AppLayout';

const guides = [
    ['Connect your account', 'Where to find sandbox and live credentials in the Tamara merchant portal.'],
    ['Enable Tamara at checkout', 'Configure the BigCommerce offline payment method and display name.'],
    ['Test your integration', 'Run a sandbox purchase and verify redirect and payment status behavior.'],
    ['Go live safely', 'A final checklist for changing credentials and validating production checkout.'],
];

export default function Help({ supportEmail, documentationUrl }: { supportEmail?: string; documentationUrl?: string }) {
    return (
        <AppLayout>
            <Head title="Help" />
            <PageHeader title="Help & support" description="Find answers and resources for your Tamara integration." />
            <div className="help-grid">
                <Panel className="help-primary">
                    <span className="eyebrow">GETTING STARTED</span>
                    <H3>Integration guides</H3>
                    <div className="guide-list">
                        {guides.map(([title, copy], index) => (
                            <div key={title}><span>{index + 1}</span><div><strong>{title}</strong><small>{copy}</small></div></div>
                        ))}
                    </div>
                    {documentationUrl && <Button onClick={() => window.open(documentationUrl, '_blank', 'noopener,noreferrer')} variant="secondary">Open documentation</Button>}
                </Panel>
                <div className="help-side">
                    <Panel>
                        <span className="support-icon">?</span>
                        <H3>Contact support</H3>
                        <Text color="secondary">Share your store hash and payment reference when asking about a specific transaction. Never send API credentials.</Text>
                        {supportEmail
                            ? <Button onClick={() => { window.location.href = `mailto:${supportEmail}`; }}>Email support</Button>
                            : <Text color="secondary" margin="none">Support contact details are provided by your Tamara account team.</Text>}
                    </Panel>
                    <Panel>
                        <H3>Security reminder</H3>
                        <Text color="secondary" margin="none">Tamara tokens should only be entered on the Settings page and must never be added to checkout scripts.</Text>
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}
