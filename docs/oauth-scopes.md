# BigCommerce OAuth scopes

`/api/checkout/start` calls the BigCommerce **Checkouts V3** API using the **app OAuth access token** stored at install time. If that token does not include checkout scopes, BigCommerce returns **403**.

## Required for checkout

| DevTools UI name | OAuth scope (either works) | Used for |
|---|---|---|
| **Checkouts → modify** | `store_checkout` or `store_checkouts` | `GET/POST v3/checkouts/{id}`, create order, checkout token |

## Recommended at install (full app)

| DevTools UI name | OAuth scope | Used for |
|---|---|---|
| Checkouts → modify | `store_checkout` | Checkout + Tamara start flow |
| Checkout Content → modify | `store_content_checkout` | Register `tamara-checkout.js` on checkout |
| Content → modify | `store_v2_content` | Scripts API |
| Orders → modify | `store_v2_orders` | Mark order paid after Tamara authorisation |
| Information & Settings → read-only | `store_v2_information` | Sync store metadata (`secure_url`, currency) |

## Fix the 403 error

Your store currently has scopes like `store_v2_default`, `store_v2_orders`, `store_v2_products_read_only` — **no checkout scope**.

1. Open [BigCommerce DevTools](https://devtools.bigcommerce.com/my/apps) → your app → **OAuth scopes**.
2. Enable **Checkouts (modify)** (and the recommended scopes above).
3. **Save** the app.
4. **Reinstall** the app on the store (uninstall + install, or load install URL again).
5. Confirm in the database that `stores.scopes` includes `store_checkout` or `store_checkouts`.

Until you reinstall, the old access token keeps the old scopes — saving DevTools alone is not enough.

## Verify

After reinstall, `POST /api/checkout/start` should pass scope validation. If scopes are still missing, the API returns **503** with:

```json
{
  "message": "The app is missing required BigCommerce OAuth scopes for checkout...",
  "missing_scope_groups": { "checkouts": ["store_checkout", "store_checkouts"] },
  "granted_scopes": ["store_v2_default", "..."]
}
```

## Postman note

A **Store API account** token (used to create carts in Postman) is separate from the **app OAuth token** (used by `/api/checkout/start`). Both need checkout/cart permissions for their respective calls.
