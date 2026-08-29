import { Button, H1, Text } from '@bigcommerce/big-design';
import { router } from '@inertiajs/react';
import { authorizationHeaders } from '../http';

export default function ErrorPage({ code, title, description }: { code: string; title: string; description: string }) {
    return (
        <main className="error-page">
            <div className="error-brand"><span className="brand-mark">t</span><strong>Tamara</strong></div>
            <div className="error-card">
                <span className="error-code">{code}</span>
                <H1 marginBottom="small">{title}</H1>
                <Text color="secondary">{description}</Text>
                <div className="hero-actions">
                    <Button onClick={() => router.visit('/dashboard', { headers: authorizationHeaders() })}>Return to dashboard</Button>
                    <Button onClick={() => window.location.reload()} variant="secondary">Try again</Button>
                </div>
            </div>
        </main>
    );
}
