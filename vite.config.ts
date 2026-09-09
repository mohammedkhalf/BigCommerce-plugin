import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const devServerUrl = env.VITE_DEV_SERVER_URL;

    let server: import('vite').UserConfig['server'];

    if (devServerUrl) {
        const { hostname, protocol } = new URL(devServerUrl);
        const isHttps = protocol === 'https:';

        server = {
            host: '0.0.0.0',
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
