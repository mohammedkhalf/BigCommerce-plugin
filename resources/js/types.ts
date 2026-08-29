export type Environment = 'sandbox' | 'live';

export interface StoreSummary {
    id?: number;
    hash: string;
    name?: string;
    environment?: Environment;
}

export interface UserSummary {
    id?: number;
    email?: string;
    name?: string;
    isOwner?: boolean;
}

export interface SharedProps {
    appName?: string;
    appToken?: string;
    store?: StoreSummary;
    user?: UserSummary;
    [key: string]: unknown;
}

export interface Payment {
    id: string;
    orderId?: string;
    customer?: string;
    amount: number;
    currency: string;
    status: string;
    createdAt?: string;
    updatedAt?: string;
    checkoutId?: string;
    reference?: string;
}
