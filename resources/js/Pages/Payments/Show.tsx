import { Button, H2, H3, Panel, Text } from '@bigcommerce/big-design';
import { Head, router } from '@inertiajs/react';
import PageHeader from '../../Components/PageHeader';
import PaymentStatusBadge from '../../Components/PaymentStatusBadge';
import { authorizationHeaders } from '../../http';
import AppLayout from '../../Layouts/AppLayout';
import type { Payment } from '../../types';

const display = (value?: string) => value || 'Not provided';

export default function PaymentShow({ payment }: { payment?: Payment }) {
    const item: Payment = payment ?? { id: '—', amount: 0, currency: 'SAR', status: 'unknown' };
    const amount = new Intl.NumberFormat('en', { style: 'currency', currency: item.currency }).format(item.amount);
    const back = () => router.visit('/payments', { headers: authorizationHeaders() });

    return (
        <AppLayout>
            <Head title={`Payment ${item.id}`} />
            <PageHeader
                title={item.orderId ? `Order #${item.orderId}` : 'Payment details'}
                description={`Payment reference ${display(item.reference ?? item.id)}`}
                actions={<><Button onClick={back} variant="secondary">Back to payments</Button></>}
            />
            <div className="payment-summary">
                <Panel className="amount-card">
                    <Text color="secondary" marginBottom="xSmall">Payment amount</Text>
                    <H2 marginBottom="small">{amount}</H2>
                    <PaymentStatusBadge status={item.status} />
                </Panel>
                <Panel>
                    <H3 marginBottom="medium">Payment information</H3>
                    <div className="details-grid">
                        <div><small>Payment ID</small><strong>{item.id}</strong></div>
                        <div><small>Order ID</small><strong>{display(item.orderId)}</strong></div>
                        <div><small>Checkout ID</small><strong>{display(item.checkoutId)}</strong></div>
                        <div><small>Customer</small><strong>{display(item.customer)}</strong></div>
                        <div><small>Created</small><strong>{display(item.createdAt)}</strong></div>
                        <div><small>Last updated</small><strong>{display(item.updatedAt)}</strong></div>
                    </div>
                </Panel>
            </div>
            <Panel>
                <H3 marginBottom="large">Timeline</H3>
                <div className="timeline">
                    <div className="timeline-event current"><span /><div><strong>Current status: {item.status}</strong><small>{display(item.updatedAt ?? item.createdAt)}</small></div></div>
                    <div className="timeline-event"><span /><div><strong>Payment initiated</strong><small>{display(item.createdAt)}</small></div></div>
                </div>
            </Panel>
        </AppLayout>
    );
}
