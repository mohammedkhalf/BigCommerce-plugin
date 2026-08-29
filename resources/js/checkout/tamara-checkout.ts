type CheckoutWindow = Window & {
    checkout?: { id?: string; checkoutId?: string };
    checkoutId?: string;
    BCData?: { storeHash?: string; checkoutId?: string };
};

interface CheckoutStartResponse {
    redirect_url?: string;
    redirectUrl?: string;
    url?: string;
}

export interface CheckoutAdapter {
    mount(): void;
    unmount(): void;
    isTamaraSelected(): boolean;
    getCheckoutId(): string | null;
}

const TAMARA_PATTERN = /\btamara\b/i;
const PAYMENT_INPUTS = [
    'input[name*="payment"][type="radio"]:checked',
    'input[name*="gateway"][type="radio"]:checked',
    'input[name*="method"][type="radio"]:checked',
].join(',');

export class BigCommerceCheckoutAdapter implements CheckoutAdapter {
    private submitting = false;
    private observer?: MutationObserver;

    mount(): void {
        document.addEventListener('submit', this.handleSubmit, true);
        document.addEventListener('click', this.handleClick, true);
        this.observer = new MutationObserver(() => this.clearErrorWhenPaymentChanges());
        this.observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['checked', 'aria-checked'] });
    }

    unmount(): void {
        document.removeEventListener('submit', this.handleSubmit, true);
        document.removeEventListener('click', this.handleClick, true);
        this.observer?.disconnect();
    }

    isTamaraSelected(): boolean {
        const checked = document.querySelector<HTMLInputElement>(PAYMENT_INPUTS);
        if (checked) {
            const associated = checked.id ? document.querySelector(`label[for="${CSS.escape(checked.id)}"]`) : null;
            const container = checked.closest('label, [data-test*="payment"], [class*="payment"]');
            return TAMARA_PATTERN.test([checked.value, checked.getAttribute('aria-label'), associated?.textContent, container?.textContent].filter(Boolean).join(' '));
        }

        const selected = Array.from(document.querySelectorAll<HTMLElement>('[aria-checked="true"], [class*="selected"], [data-selected="true"]'));
        return selected.some((element) => TAMARA_PATTERN.test(element.textContent ?? '') && this.looksLikePaymentMethod(element));
    }

    getCheckoutId(): string | null {
        const win = window as CheckoutWindow;
        const candidates = [
            win.checkout?.id,
            win.checkout?.checkoutId,
            win.checkoutId,
            win.BCData?.checkoutId,
            document.querySelector<HTMLElement>('[data-checkout-id]')?.dataset.checkoutId,
            document.querySelector<HTMLMetaElement>('meta[name="checkout-id"]')?.content,
            this.idFromLocation(),
        ];
        return candidates.find((value): value is string => typeof value === 'string' && /^[a-z0-9_-]{6,128}$/i.test(value)) ?? null;
    }

    private handleClick = (event: MouseEvent): void => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement | HTMLInputElement>('button, input[type="submit"]');
        if (!button || !this.isPlaceOrderControl(button) || !this.isTamaraSelected()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void this.startCheckout(button);
    };

    private handleSubmit = (event: SubmitEvent): void => {
        if (!this.isTamaraSelected()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : undefined;
        void this.startCheckout(submitter);
    };

    private async startCheckout(control?: HTMLElement): Promise<void> {
        if (this.submitting) return;
        const checkoutId = this.getCheckoutId();
        const storeHash = this.getStoreHash();
        if (!checkoutId || !storeHash) {
            this.showError('Tamara could not start because checkout information is unavailable. Refresh the page and try again.');
            return;
        }

        this.submitting = true;
        this.setLoading(control, true);
        this.removeError();
        const abort = new AbortController();
        const timer = window.setTimeout(() => abort.abort(), 15000);

        try {
            const response = await fetch(this.apiUrl(), {
                method: 'POST',
                credentials: 'omit',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Store-Hash': storeHash },
                body: JSON.stringify({ store_hash: storeHash, checkout_id: checkoutId }),
                signal: abort.signal,
            });
            const payload = await response.json().catch(() => ({})) as CheckoutStartResponse & { message?: string };
            if (!response.ok) throw new Error(payload.message || 'Tamara is temporarily unavailable. Please try again.');
            const redirect = payload.redirect_url ?? payload.redirectUrl ?? payload.url;
            if (!redirect || !this.isSafeRedirect(redirect)) throw new Error('Tamara returned an invalid redirect.');
            window.location.assign(redirect);
        } catch (error) {
            this.submitting = false;
            this.setLoading(control, false);
            const message = error instanceof DOMException && error.name === 'AbortError'
                ? 'Tamara took too long to respond. Please try again.'
                : error instanceof Error ? error.message : 'Tamara is temporarily unavailable. Please try again.';
            this.showError(message);
        } finally {
            window.clearTimeout(timer);
        }
    }

    private getStoreHash(): string | null {
        const win = window as CheckoutWindow;
        const script = document.currentScript as HTMLScriptElement | null;
        const scriptUrl = script?.src ?? document.querySelector<HTMLScriptElement>('script[src*="tamara-checkout"]')?.src;
        const value = script?.dataset.storeHash
            ?? document.querySelector<HTMLScriptElement>('script[data-store-hash][src*="tamara-checkout"]')?.dataset.storeHash
            ?? document.querySelector<HTMLMetaElement>('meta[name="store-hash"]')?.content
            ?? win.BCData?.storeHash
            ?? (scriptUrl ? new URL(scriptUrl).searchParams.get('store_hash') : null);
        return value && /^[a-z0-9]{2,32}$/i.test(value) ? value : null;
    }

    private apiUrl(): string {
        const source = document.querySelector<HTMLScriptElement>('script[src*="tamara-checkout"]')?.src;
        return source ? new URL('/api/checkout/start', source).toString() : '/api/checkout/start';
    }

    private idFromLocation(): string | undefined {
        const query = new URLSearchParams(window.location.search);
        const fromQuery = query.get('checkoutId') ?? query.get('checkout_id');
        if (fromQuery) return fromQuery;
        return window.location.pathname.match(/\/checkout\/([a-z0-9_-]{6,128})/i)?.[1];
    }

    private looksLikePaymentMethod(element: HTMLElement): boolean {
        return Boolean(element.closest('[data-test*="payment"], [class*="payment"], [id*="payment"]'));
    }

    private isPlaceOrderControl(control: HTMLElement): boolean {
        const text = `${control.textContent ?? ''} ${control.getAttribute('value') ?? ''} ${control.getAttribute('aria-label') ?? ''}`;
        return /place\s*order|complete\s*order|pay\s*now/i.test(text) || /place-order|submit-order/i.test(control.getAttribute('data-test') ?? '');
    }

    private isSafeRedirect(value: string): boolean {
        try {
            const url = new URL(value, window.location.origin);
            return url.protocol === 'https:' || (url.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(url.hostname));
        } catch {
            return false;
        }
    }

    private setLoading(control: HTMLElement | undefined, loading: boolean): void {
        if (!control) return;
        if ('disabled' in control) (control as HTMLButtonElement).disabled = loading;
        control.setAttribute('aria-busy', String(loading));
    }

    private showError(message: string): void {
        const host = document.querySelector('[data-test*="payment"], form[action*="checkout"]') ?? document.body;
        const alert = document.createElement('div');
        alert.id = 'tamara-checkout-error';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        Object.assign(alert.style, { color: '#9d1c1c', background: '#fff0f0', border: '1px solid #e8a4a4', borderRadius: '4px', margin: '12px 0', padding: '12px 16px' });
        host.append(alert);
    }

    private removeError(): void {
        document.getElementById('tamara-checkout-error')?.remove();
    }

    private clearErrorWhenPaymentChanges(): void {
        if (!this.isTamaraSelected()) this.removeError();
    }
}

const adapter = new BigCommerceCheckoutAdapter();
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => adapter.mount(), { once: true });
else adapter.mount();
