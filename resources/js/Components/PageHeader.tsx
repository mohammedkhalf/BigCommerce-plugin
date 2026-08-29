import { H1, Text } from '@bigcommerce/big-design';
import type { ReactNode } from 'react';

export default function PageHeader({ title, description, actions }: { title: string; description: string; actions?: ReactNode }) {
    return (
        <div className="page-header">
            <div>
                <H1 marginBottom="xxSmall">{title}</H1>
                <Text color="secondary" margin="none">{description}</Text>
            </div>
            {actions && <div className="page-actions">{actions}</div>}
        </div>
    );
}
