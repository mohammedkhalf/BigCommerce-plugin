import { Head } from '@inertiajs/react';
import ErrorPage from '../../Components/ErrorPage';

export default function Unauthorized() {
    return (
        <>
            <Head title="Session expired" />
            <ErrorPage
                code="401"
                title="Session expired"
                description="Open Tamara again from your BigCommerce admin panel to continue."
            />
        </>
    );
}
