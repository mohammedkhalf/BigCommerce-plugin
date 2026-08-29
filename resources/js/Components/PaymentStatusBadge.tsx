import { Badge } from '@bigcommerce/big-design';

export default function PaymentStatusBadge({ status }: { status?: string }) {
    const normalized = (status || 'unknown').toLowerCase();
    const variants = {
        approved: 'success',
        captured: 'success',
        authorised: 'success',
        canceled: 'danger',
        cancelled: 'danger',
        declined: 'danger',
        refunded: 'secondary',
        pending: 'warning',
        new: 'primary',
    } as const;
    const variant = variants[normalized as keyof typeof variants] ?? 'secondary';
    const label = normalized === 'unknown' ? 'Unknown' : normalized.charAt(0).toUpperCase() + normalized.slice(1);

    return <Badge label={label} variant={variant} />;
}
