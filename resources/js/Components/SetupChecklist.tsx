import { H3, Panel, Text } from '@bigcommerce/big-design';

export interface SetupStep {
    label: string;
    description: string;
    complete?: boolean;
}

export default function SetupChecklist({ steps }: { steps?: SetupStep[] }) {
    const items = steps ?? [
        { label: 'Connect Tamara', description: 'Add your merchant credentials.', complete: false },
        { label: 'Configure checkout', description: 'Enable Tamara in your store payment settings.', complete: false },
        { label: 'Place a test order', description: 'Verify the complete customer journey.', complete: false },
    ];
    const complete = items.filter((step) => step.complete).length;

    return (
        <Panel>
            <div className="panel-heading">
                <div>
                    <H3 marginBottom="xxSmall">Setup checklist</H3>
                    <Text color="secondary" margin="none">{complete} of {items.length} complete</Text>
                </div>
                <span className="progress-label">{Math.round((complete / Math.max(items.length, 1)) * 100)}%</span>
            </div>
            <div className="progress-track"><span style={{ width: `${(complete / Math.max(items.length, 1)) * 100}%` }} /></div>
            <ol className="checklist">
                {items.map((step, index) => (
                    <li className={step.complete ? 'complete' : ''} key={step.label}>
                        <span className="check-number">{step.complete ? '✓' : index + 1}</span>
                        <div><strong>{step.label}</strong><small>{step.description}</small></div>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}
