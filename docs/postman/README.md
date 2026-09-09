# Postman: BigCommerce Cart → Tamara Checkout

Import these files into Postman:

1. [Tamara-Checkout.postman_collection.json](./Tamara-Checkout.postman_collection.json)
2. [Tamara-Checkout.postman_environment.json](./Tamara-Checkout.postman_environment.json)

## Setup

### 1. Create a Store API account (required)

The app's OAuth token does **not** include cart/checkout scopes. Create a separate API account in BigCommerce:

**Settings → Store-level API accounts → Create API account**

Enable these scopes:

- Products — read-only (or modify)
- Carts — modify
- Checkouts — modify

Copy the **Access Token** into the Postman environment variable `bc_access_token`.

### 2. Configure Postman environment

| Variable | Value |
|---|---|
| `bc_access_token` | Store API account token |
| `store_hash` | Your store hash (e.g. `xujmud2cok`) |
| `store_origin` | Store secure URL, e.g. `https://store-{hash}.mybigcommerce.com` |
| `app_url` | Your Laravel app URL (ngrok in local dev) |

`store_origin` must match the host stored in `stores.metadata.secure_url` in the app database (used for CORS/Origin validation).

### 3. App prerequisites

- App installed on the store via OAuth
- Tamara sandbox credentials saved and **enabled** in Settings
- `php artisan queue:work` running
- `APP_URL` publicly reachable (ngrok) so Tamara can call webhooks

## Run order

Execute requests **1 → 1b → 2 → 7** in sequence (use Postman Collection Runner or run manually):

| # | Request | Saves |
|---|---|---|
| 1 | Get Products | `product_id` |
| 1b | Get Product Variants | `variant_id` |
| 2 | Create Cart | `checkout_id` |
| 3 | Get Checkout | `line_item_id` |
| 4 | Add Billing Address | — |
| 5 | Add Consignment | — |
| 6 | Verify Checkout | confirms `grandTotal > 0` |
| 7 | Start Tamara Checkout | returns `checkout_url` |

**Note:** Products with options (size, color, etc.) require `variant_id` in the cart line item, not `product_id`. Step **1b** fetches the first variant automatically.

Open the returned `checkout_url` in a browser to complete Tamara sandbox payment.

## Troubleshooting

| Error | Fix |
|---|---|
| BC `422` variant ID required | Run step **1b** first; use `variant_id` not `product_id` in Create Cart |
| BC `403` on cart/checkout | Use Store API token with Carts + Checkouts scopes |
| App `403 Origin is not allowed` | Set `store_origin` to match `stores.metadata.secure_url` host |
| App `404` on `/api/checkout/start` | Enable Tamara in app Settings; confirm store is installed |
| App `422` invalid total/currency | Complete steps 4–5; ensure currency is in Tamara allowlist |
| No `checkout_url` in response | Check Tamara sandbox credentials and queue/logs |
