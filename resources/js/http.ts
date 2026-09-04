import axios from 'axios';

const APP_TOKEN_STORAGE_KEY = 'tamara_app_token';

let appToken: string | undefined;

export function configureAppToken(token?: string): void {
    appToken = token;

    if (typeof window !== 'undefined') {
        if (token) {
            window.sessionStorage.setItem(APP_TOKEN_STORAGE_KEY, token);
        } else {
            window.sessionStorage.removeItem(APP_TOKEN_STORAGE_KEY);
        }
    }

    if (token) {
        axios.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete axios.defaults.headers.common.Authorization;
    }
}

export function storedAppToken(): string | undefined {
    if (appToken) {
        return appToken;
    }

    if (typeof window === 'undefined') {
        return undefined;
    }

    const stored = window.sessionStorage.getItem(APP_TOKEN_STORAGE_KEY);

    if (stored) {
        appToken = stored;
    }

    return appToken;
}

export function authorizationHeaders(): Record<string, string> {
    const token = storedAppToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

export async function authorizedFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers);
    const token = storedAppToken();

    if (token) headers.set('Authorization', `Bearer ${token}`);
    headers.set('Accept', 'application/json');

    return fetch(input, { ...init, headers });
}
