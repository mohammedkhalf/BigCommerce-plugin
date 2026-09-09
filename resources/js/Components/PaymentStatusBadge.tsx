import { Badge } from '@bigcommerce/big-design';

export default function PaymentStatusBadge({ status }: { status?: string }) {
    const normalized = (status || 'unknown').toLowerCase();
    const variants = {
        approved: 'success',
        captured: 'success',
        authorised: 'success',
        completed: 'success',
        canceled: 'danger',
        cancelled: 'danger',
        declined: 'danger',
        refunded: 'secondary',
        pending: 'warning',
        new: 'primary',
    } as const;
    const labels: Record<string, string> = {
        authorised: 'Authorized',
        approved: 'Approved',
        completed: 'Completed',
        pending: 'Pending',
        declined: 'Declined',
        cancelled: 'Cancelled',
        captured: 'Full captured',
        refunded: 'Refunded',
    };
    const variant = variants[normalized as keyof typeof variants] ?? 'secondary';
    const label = labels[normalized] ?? (normalized === 'unknown' ? 'Unknown' : normalized.charAt(0).toUpperCase() + normalized.slice(1));

    return <Badge label={label} variant={variant} />;
}
