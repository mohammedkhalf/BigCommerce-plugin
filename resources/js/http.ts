import axios from 'axios';

let appToken: string | undefined;

export function configureAppToken(token?: string): void {
    appToken = token;
    if (token) {
        axios.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete axios.defaults.headers.common.Authorization;
    }
}

export function authorizationHeaders(): Record<string, string> {
    return appToken ? { Authorization: `Bearer ${appToken}` } : {};
}

export async function authorizedFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers);
    if (appToken) headers.set('Authorization', `Bearer ${appToken}`);
    headers.set('Accept', 'application/json');

    return fetch(input, { ...init, headers });
}
