import { Head } from '@inertiajs/react';
import ErrorPage from '../../Components/ErrorPage';

export default function Forbidden() {
    return <><Head title="Access denied" /><ErrorPage code="403" title="Access denied" description="Your account does not have permission to view this page. Ask the store owner to review your access." /></>;
}
