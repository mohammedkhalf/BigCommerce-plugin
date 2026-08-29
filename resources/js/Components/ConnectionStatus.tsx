import { Badge, Button, H3, Panel, Text } from '@bigcommerce/big-design';

export default function ConnectionStatus({
    connected = false,
    environment = 'sandbox',
    onManage,
}: {
    connected?: boolean;
    environment?: 'sandbox' | 'live';
    onManage?: () => void;
}) {
    return (
        <Panel className="connection-panel">
            <div className="connection-row">
                <span className={connected ? 'status-orb connected' : 'status-orb'} aria-hidden="true" />
                <div className="connection-copy">
                    <div className="inline-title">
                        <H3 margin="none">Tamara connection</H3>
                        <Badge label={connected ? 'Connected' : 'Setup required'} variant={connected ? 'success' : 'warning'} />
                    </div>
                    <Text color="secondary" margin="none">
                        {connected
                            ? `Your ${environment} credentials are configured.`
                            : 'Connect your Tamara merchant account to start accepting payments.'}
                    </Text>
                </div>
                {onManage && <Button onClick={onManage} variant="secondary">{connected ? 'Manage' : 'Connect'}</Button>}
            </div>
        </Panel>
    );
}
