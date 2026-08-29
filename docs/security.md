# Security

- Store access tokens and all Tamara credentials use Laravel encrypted casts and are hidden from serialization.
- Admin endpoints require the short-lived store-context bearer JWT. Responses expose only credential existence flags.
- Tamara notifications require an HS256 bearer JWT signed with that store's notification token.
- BigCommerce currently does not document a signature header for standard webhooks. If `X-BC-Signature` is supplied, this app verifies base64 HMAC-SHA256 of the raw body with the app client secret. Producer store identity and event idempotency are always enforced. Restrict the webhook route by provider IP/network controls when available.
- Checkout totals, currency, customer, and line items are always loaded from BigCommerce. Browser return parameters do not assert payment success.
- Checkout CORS reflects only the installed store's recorded secure host. Ensure store metadata provisioning has completed before enabling Tamara.
- Never log request authorization headers, encrypted model attributes, raw credentials, or checkout tokens.

Rotate `APP_KEY` only with a planned credential re-encryption migration. Rotate provider tokens immediately after suspected exposure.
