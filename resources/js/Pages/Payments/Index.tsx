import { Button, H3, Input, Panel, Table, Text } from '@bigcommerce/big-design';
import type { TableColumn } from '@bigcommerce/big-design';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PageHeader from '../../Components/PageHeader';
import PaymentStatusBadge from '../../Components/PaymentStatusBadge';
import { authorizationHeaders } from '../../http';
import AppLayout from '../../Layouts/AppLayout';
import type { Payment } from '../../types';

const money = (payment: Payment) =>
    new Intl.NumberFormat('en', { style: 'currency', currency: payment.currency }).format(payment.amount);

interface PaginatedPayments {
    data: Payment[];
    prev_page_url?: string | null;
    next_page_url?: string | null;
    current_page: number;
    last_page: number;
}

export default function PaymentsIndex({ payments: result }: { payments?: PaginatedPayments }) {
    const payments = result?.data ?? [];
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('all');
    const visit = (url: string) => router.visit(url, { headers: authorizationHeaders() });
    const filtered = payments.filter((payment) => {
        const matchesQuery = `${payment.id} ${payment.orderId ?? ''} ${payment.customer ?? ''}`.toLowerCase().includes(query.toLowerCase());
        return matchesQuery && (status === 'all' || payment.status.toLowerCase() === status || (status === 'authorized' && payment.status.toLowerCase() === 'authorised'));
    });
    const columns = useMemo<TableColumn<Payment>[]>(() => [
        { header: 'Order', hash: 'order', render: (item) => <button className="table-link" onClick={() => visit(`/payments/${item.id}`)}>#{item.orderId ?? item.id}</button> },
        { header: 'Customer', hash: 'customer', render: (item) => item.customer ?? '—' },
        { header: 'Amount', hash: 'amount', render: (item) => <strong>{money(item)}</strong> },
        { header: 'Status', hash: 'status', render: (item) => <PaymentStatusBadge status={item.status} /> },
        { header: 'Created', hash: 'createdAt', render: (item) => item.createdAt ?? '—' },
    ], []);

    return (
        <AppLayout>
            <Head title="Payments" />
            <PageHeader title="Payments" description="Review Tamara payment attempts and their current status." />
            <Panel>
                <div className="filters">
                    <Input aria-label="Search payments" onChange={(event) => setQuery(event.target.value)} placeholder="Search order, payment or customer" value={query} />
                    <select aria-label="Filter payment status" onChange={(event) => setStatus(event.target.value)} value={status}>
                        <option value="all">All statuses</option>
                        <option value="authorized">Authorized</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="declined">Declined</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                {filtered.length ? <Table columns={columns} itemName="payments" items={filtered} keyField="id" /> : (
                    <div className="empty-state">
                        <span className="empty-icon">⌕</span>
                        <H3 marginBottom="xxSmall">{payments.length ? 'No matching payments' : 'No payments yet'}</H3>
                        <Text color="secondary">{payments.length ? 'Try changing your search or status filter.' : 'Payment activity will appear here after customers use Tamara.'}</Text>
                        {query && <Button onClick={() => { setQuery(''); setStatus('all'); }} variant="secondary">Clear filters</Button>}
                    </div>
                )}
                {result && result.last_page > 1 && (
                    <div className="form-actions">
                        <Button disabled={!result.prev_page_url} onClick={() => result.prev_page_url && visit(result.prev_page_url)} variant="secondary">Previous</Button>
                        <Text>Page {result.current_page} of {result.last_page}</Text>
                        <Button disabled={!result.next_page_url} onClick={() => result.next_page_url && visit(result.next_page_url)} variant="secondary">Next</Button>
                    </div>
                )}
            </Panel>
        </AppLayout>
    );
}
