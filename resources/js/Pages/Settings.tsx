import { Badge, H3, Panel, Text } from '@bigcommerce/big-design';
import { Head } from '@inertiajs/react';
import ConnectionStatus from '../Components/ConnectionStatus';
import PageHeader from '../Components/PageHeader';
import TamaraKeysForm from '../Components/TamaraKeysForm';
import AppLayout from '../Layouts/AppLayout';
import type { Environment } from '../types';

interface Props {
    connected?: boolean;
    environment?: Environment;
    merchantTokenConfigured?: boolean;
    notificationTokenConfigured?: boolean;
    saveEndpoint?: string;
    testEndpoint?: string;
    enableEndpoint?: string;
    disconnectEndpoint?: string;
    enabled?: boolean;
    webhookUrl?: string;
}

export default function Settings(props: Props) {
    return (
        <AppLayout>
            <Head title="Settings" />
            <PageHeader title="Settings" description="Manage your Tamara connection and checkout configuration." />
            <ConnectionStatus connected={props.connected} environment={props.environment} />
            <div className="settings-grid">
                <TamaraKeysForm
                    endpoint={props.saveEndpoint}
                    testEndpoint={props.testEndpoint}
                    enableEndpoint={props.enableEndpoint}
                    disconnectEndpoint={props.disconnectEndpoint}
                    enabled={props.enabled}
                    environment={props.environment}
                    merchantTokenConfigured={props.merchantTokenConfigured}
                    notificationTokenConfigured={props.notificationTokenConfigured}
                />
                <div className="settings-side">
                    <Panel>
                        <div className="inline-title"><H3 margin="none">Checkout method</H3><Badge label={props.connected ? 'Available' : 'Not ready'} variant={props.connected ? 'success' : 'secondary'} /></div>
                        <Text color="secondary">Tamara is shown only when the offline payment method is enabled and selected at checkout.</Text>
                        <div className="detail-row"><span>Display name</span><strong>Tamara</strong></div>
                        <div className="detail-row"><span>Current mode</span><strong>{props.environment === 'live' ? 'Live' : 'Sandbox'}</strong></div>
                    </Panel>
                    <Panel>
                        <H3 marginBottom="xSmall">Webhook endpoint</H3>
                        <Text color="secondary">Add this endpoint in your Tamara merchant portal when webhook delivery is enabled.</Text>
                        <code className="code-value">{props.webhookUrl ?? 'Provided after connection'}</code>
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}
