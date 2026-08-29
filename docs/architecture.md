# Architecture

The Laravel application is the system of record for store installations, encrypted Tamara credentials, payment sessions, registered BigCommerce resources, and webhook idempotency.

OAuth installation queues `ProvisionStoreAfterInstall`, which synchronizes store metadata and idempotently registers the checkout script and shipment/refund webhooks. The storefront script sends only a store hash and checkout ID. The backend reloads the checkout from BigCommerce, creates the BigCommerce order and token, then creates a Tamara checkout. Browser return URLs never determine payment state; Tamara is queried before confirmation redirect.

Tamara and BigCommerce webhooks are persisted before queue dispatch. The unique provider/event identifier prevents duplicate work. Tamara approval authorisation is followed by a separate BigCommerce paid-status job. Shipment and refund events dispatch capture and refund jobs.

Run queue workers separately from the web process. Failed jobs remain retryable and webhook records retain bounded error details without credentials.
