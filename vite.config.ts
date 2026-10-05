import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const devServerUrl = env.VITE_DEV_SERVER_URL?.replace(/\/$/, '');
    const appUrl = env.APP_URL?.replace(/\/$/, '');

    let server: import('vite').UserConfig['server'] = {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // Allow requests from ngrok / other tunnel hostnames (Vite 6+ blocks unknown hosts).
        allowedHosts: true,
        cors: {
            origin: [appUrl, devServerUrl].filter(Boolean) as string[],
        },
    };

    if (devServerUrl) {
        const { hostname, protocol } = new URL(devServerUrl);
        const isHttps = protocol === 'https:';

        server = {
            ...server,
            // Laravel writes this into public/hot so @vite script tags use the tunnel URL.
            origin: devServerUrl,
            hmr: {
                host: hostname,
                protocol: isHttps ? 'wss' : 'ws',
                clientPort: isHttps ? 443 : undefined,
            },
        };
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
        ],
        server,
    };
});
