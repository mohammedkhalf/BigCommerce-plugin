import { Head } from '@inertiajs/react';
import ErrorPage from '../../Components/ErrorPage';

export default function Server() {
    return <><Head title="Something went wrong" /><ErrorPage code="500" title="Something went wrong" description="We could not load this page right now. Try again, or contact support if the problem continues." /></>;
}
