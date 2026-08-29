import { Button, H3, Input, Message, Panel, Text } from '@bigcommerce/big-design';
import { FormEvent, useState } from 'react';
import { authorizedFetch } from '../http';
import type { Environment } from '../types';

interface KeysFormProps {
    environment?: Environment;
    merchantTokenConfigured?: boolean;
    notificationTokenConfigured?: boolean;
    endpoint?: string;
    testEndpoint?: string;
    enableEndpoint?: string;
    disconnectEndpoint?: string;
    enabled?: boolean;
    onSaved?: () => void;
}

export default function TamaraKeysForm({
    environment = 'sandbox',
    merchantTokenConfigured = false,
    notificationTokenConfigured = false,
    endpoint,
    testEndpoint,
    enableEndpoint,
    disconnectEndpoint,
    enabled = false,
    onSaved,
}: KeysFormProps) {
    const [mode, setMode] = useState<Environment>(environment);
    const [merchantToken, setMerchantToken] = useState('');
    const [notificationToken, setNotificationToken] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string }>();

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!endpoint) {
            setMessage({ type: 'error', text: 'Credential saving is not available yet.' });
            return;
        }
        setSaving(true);
        setMessage(undefined);
        try {
            const response = await authorizedFetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ environment: mode, merchantToken, notificationToken }),
            });
            if (!response.ok) throw new Error('Unable to save credentials.');
            setMerchantToken('');
            setNotificationToken('');
            setMessage({ type: 'success', text: 'Credentials saved securely.' });
            onSaved?.();
        } catch (error) {
            setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Unable to save credentials.' });
        } finally {
            setSaving(false);
        }
    };

    const action = async (url: string | undefined, method = 'POST', body?: object) => {
        if (!url) return;
        setSaving(true);
        setMessage(undefined);
        try {
            const response = await authorizedFetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
            });
            if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'Request failed.');
            setMessage({ type: 'success', text: 'Settings updated.' });
            onSaved?.();
        } catch (error) {
            setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Request failed.' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Panel>
            <H3 marginBottom="xxSmall">API credentials</H3>
            <Text color="secondary">Credentials are encrypted by the server and are never displayed after saving.</Text>
            {message && <Message header={message.text} messages={[]} type={message.type} marginBottom="medium" />}
            <form className="keys-form" onSubmit={submit}>
                <label className="field-label" htmlFor="environment">Environment</label>
                <select id="environment" value={mode} onChange={(event) => setMode(event.target.value as Environment)}>
                    <option value="sandbox">Sandbox</option>
                    <option value="live">Live</option>
                </select>
                <Input
                    autoComplete="off"
                    label="Merchant token"
                    onChange={(event) => setMerchantToken(event.target.value)}
                    placeholder={merchantTokenConfigured ? 'Configured •••••••• (leave blank to keep)' : 'Enter merchant token'}
                    type="password"
                    value={merchantToken}
                />
                <Input
                    autoComplete="off"
                    label="Notification token"
                    onChange={(event) => setNotificationToken(event.target.value)}
                    placeholder={notificationTokenConfigured ? 'Configured •••••••• (leave blank to keep)' : 'Enter notification token'}
                    type="password"
                    value={notificationToken}
                />
                <div className="form-actions">
                    <Button isLoading={saving} type="submit">Save credentials</Button>
                    <Button disabled={saving || !merchantTokenConfigured} onClick={() => void action(testEndpoint)} type="button" variant="secondary">Test</Button>
                    <Button disabled={saving || !notificationTokenConfigured} onClick={() => void action(enableEndpoint, 'POST', { enabled: !enabled })} type="button" variant="secondary">{enabled ? 'Disable' : 'Enable'}</Button>
                    <Button disabled={saving} onClick={() => void action(disconnectEndpoint, 'DELETE')} type="button" variant="secondary">Disconnect</Button>
                </div>
            </form>
        </Panel>
    );
}
