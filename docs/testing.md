# Testing and deployment

## Verification

Run:

```sh
php artisan test
npm run build
composer audit
npm audit
```

HTTP tests should use Laravel HTTP fakes for both BigCommerce and Tamara. Queue fakes are recommended when asserting webhook dispatch; run selected job handlers directly when testing state transitions.

## Production

Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, a persistent database/cache, and a non-sync queue. Configure the BigCommerce client values, `APP_SESSION_JWT_SECRET`, provider URLs, paid order status, and optional checkout script SRI hash through environment variables. Do not store merchant credentials in environment files; merchants enter them through the authenticated settings endpoint.

Run migrations, build frontend assets, cache configuration/routes, and run supervised queue workers with retries. Configure TLS, database backups, centralized redacted logs, failed-job alerting, webhook availability monitoring, and scheduled dependency/security scans. Verify OAuth callbacks, script/webhook registration, sandbox checkout, authorisation, capture, refund, cancellation, and duplicate webhook delivery before live enablement.
