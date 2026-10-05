#!/usr/bin/env node
/**
 * Single-hostname reverse proxy for local ngrok development.
 *
 * Routes Vite HMR/assets to the Vite dev server and everything else to Laravel,
 * so @vite script tags can use the same public ngrok hostname as APP_URL.
 *
 * Usage:
 *   node scripts/dev-tunnel-proxy.mjs
 *   # then point ngrok at PROXY_PORT (default 8080) instead of artisan serve
 */
import http from 'node:http';
import { URL } from 'node:url';

const proxyPort = Number(process.env.PROXY_PORT || 8080);
const laravelTarget = process.env.LARAVEL_URL || 'http://127.0.0.1:8001';
const viteTarget = process.env.VITE_URL || 'http://127.0.0.1:5173';

function isViteRequest(req) {
    const url = req.url || '/';
    const path = url.split('?')[0];

    return (
        path.startsWith('/@vite')
        || path.startsWith('/@fs')
        || path.startsWith('/@id')
        || path.startsWith('/@react-refresh')
        || path.startsWith('/resources/')
        || path.startsWith('/node_modules/')
        || path.includes('.vite/')
        || url.includes('?import')
        || url.includes('&import')
        || url.includes('?direct')
        || Boolean(req.headers['sec-websocket-protocol']?.includes('vite-hmr'))
    );
}

function proxy(req, res, target) {
    const targetUrl = new URL(req.url || '/', target);
    const headers = { ...req.headers, host: targetUrl.host };

    const upstream = http.request(
        {
            protocol: targetUrl.protocol,
            hostname: targetUrl.hostname,
            port: targetUrl.port,
            path: targetUrl.pathname + targetUrl.search,
            method: req.method,
            headers,
        },
        (upstreamRes) => {
            res.writeHead(upstreamRes.statusCode || 502, upstreamRes.headers);
            upstreamRes.pipe(res);
        },
    );

    upstream.on('error', (error) => {
        res.writeHead(502, { 'Content-Type': 'text/plain' });
        res.end(`Proxy error (${target}): ${error.message}`);
    });

    req.pipe(upstream);
}

function proxyUpgrade(req, socket, head, target) {
    const targetUrl = new URL(req.url || '/', target);
    const headers = { ...req.headers, host: targetUrl.host };

    const upstream = http.request({
        protocol: targetUrl.protocol,
        hostname: targetUrl.hostname,
        port: targetUrl.port,
        path: targetUrl.pathname + targetUrl.search,
        method: 'GET',
        headers,
    });

    upstream.on('upgrade', (upstreamRes, upstreamSocket, upstreamHead) => {
        socket.write(
            `HTTP/1.1 101 Switching Protocols\r\n`
            + Object.entries(upstreamRes.headers)
                .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : value}`)
                .join('\r\n')
            + '\r\n\r\n',
        );
        if (upstreamHead?.length) {
            socket.write(upstreamHead);
        }
        upstreamSocket.pipe(socket);
        socket.pipe(upstreamSocket);
    });

    upstream.on('error', () => {
        socket.destroy();
    });

    upstream.end();
}

const server = http.createServer((req, res) => {
    const target = isViteRequest(req) ? viteTarget : laravelTarget;
    proxy(req, res, target);
});

server.on('upgrade', (req, socket, head) => {
    const target = isViteRequest(req) ? viteTarget : laravelTarget;
    proxyUpgrade(req, socket, head, target);
});

server.listen(proxyPort, '0.0.0.0', () => {
    console.log(`Dev tunnel proxy listening on http://127.0.0.1:${proxyPort}`);
    console.log(`  Laravel → ${laravelTarget}`);
    console.log(`  Vite    → ${viteTarget}`);
    console.log('Point ngrok at this proxy port, and set:');
    console.log('  VITE_DEV_SERVER_URL=${APP_URL}');
});
