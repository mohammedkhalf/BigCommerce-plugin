import { Head } from '@inertiajs/react';
import ErrorPage from '../../Components/ErrorPage';

export default function NotFound() {
    return <><Head title="Page not found" /><ErrorPage code="404" title="Page not found" description="The page you requested may have moved or is no longer available." /></>;
}
