import { Button, H2, H3, Panel, Text } from '@bigcommerce/big-design';
import { Head, router } from '@inertiajs/react';
import ConnectionStatus from '../Components/ConnectionStatus';
import PageHeader from '../Components/PageHeader';
import PaymentStatusBadge from '../Components/PaymentStatusBadge';
import SetupChecklist, { SetupStep } from '../Components/SetupChecklist';
import { authorizationHeaders } from '../http';
import AppLayout from '../Layouts/AppLayout';
import type { Environment, Payment } from '../types';

interface Props {
    connected?: boolean;
    environment?: Environment;
    stats?: { totalVolume?: number; payments?: number; approvalRate?: number; refunds?: number; currency?: string };
    recentPayments?: Payment[];
    setupSteps?: SetupStep[];
}

const money = (amount = 0, currency = 'SAR') =>
    new Intl.NumberFormat('en', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount);

export default function Dashboard({ connected = false, environment = 'sandbox', stats = {}, recentPayments = [], setupSteps }: Props) {
    const visit = (url: string) => router.visit(url, { headers: authorizationHeaders() });
    const cards = [
        ['Payment volume', money(stats.totalVolume, stats.currency), 'Processed in selected period'],
        ['Payments', String(stats.payments ?? 0), 'Completed payment attempts'],
        ['Approval rate', `${stats.approvalRate ?? 0}%`, 'Approved payment attempts'],
        ['Refunded', money(stats.refunds, stats.currency), 'Refunded in selected period'],
    ];

    return (
        <AppLayout>
            <Head title="Dashboard" />
            <PageHeader
                title="Dashboard"
                description="Monitor your Tamara integration and recent payment activity."
                actions={<Button onClick={() => visit('/payments')}>View all payments</Button>}
            />
            <ConnectionStatus connected={connected} environment={environment} onManage={() => visit('/settings')} />
            <div className="stats-grid">
                {cards.map(([label, value, detail]) => (
                    <Panel key={label} className="stat-card">
                        <Text color="secondary" marginBottom="xSmall">{label}</Text>
                        <H2 marginBottom="xxSmall">{value}</H2>
                        <small>{detail}</small>
                    </Panel>
                ))}
            </div>
            <div className="dashboard-grid">
                <Panel>
                    <div className="panel-heading">
                        <div><H3 marginBottom="xxSmall">Recent payments</H3><Text color="secondary" margin="none">Latest activity from your store</Text></div>
                        <Button onClick={() => visit('/payments')} variant="subtle">View all</Button>
                    </div>
                    {recentPayments.length ? (
                        <div className="payment-list">
                            {recentPayments.slice(0, 5).map((payment) => (
                                <button key={payment.id} onClick={() => visit(`/payments/${payment.id}`)} type="button">
                                    <span><strong>#{payment.orderId ?? payment.id}</strong><small>{payment.customer ?? 'Customer not provided'}</small></span>
                                    <PaymentStatusBadge status={payment.status} />
                                    <strong>{money(payment.amount, payment.currency)}</strong>
                                </button>
                            ))}
                        </div>
                    ) : (
                        <div className="empty-state compact"><span className="empty-icon">↗</span><strong>No payments yet</strong><small>Payments will appear here after customers choose Tamara.</small></div>
                    )}
                </Panel>
                {!connected || setupSteps ? <SetupChecklist steps={setupSteps} /> : (
                    <Panel className="tip-card"><span className="eyebrow">QUICK TIP</span><H3>Keep checkout healthy</H3><Text color="secondary">Review declined payments and verify your active environment before switching credentials.</Text></Panel>
                )}
            </div>
        </AppLayout>
    );
}
