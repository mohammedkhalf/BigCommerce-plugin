# Tamara credentials

## "Merchant is not found" (HTTP 404)

This error comes from **Tamara**, not BigCommerce. It almost always means the **API token does not match the selected environment**.

| App setting | Tamara API base URL | Token must be from |
|---|---|---|
| **Sandbox** | `https://api-sandbox.tamara.co` | Tamara **sandbox** Partners Portal |
| **Live** | `https://api.tamara.co` | Tamara **production** Partners Portal |

Sandbox and production tokens are **not interchangeable**.

### How to fix

**Option A — Testing (recommended)**

1. Open [Tamara Partners Portal](https://partners.tamara.co) (sandbox account).
2. Copy the **sandbox** API token and notification token.
3. In the app **Settings** page:
   - Environment: **Sandbox**
   - Paste sandbox **merchant token** and **notification token**
4. Click **Test connection**, then **Enable**.

**Option B — You only have a production token**

1. In Settings, set Environment to **Live**.
2. Save production merchant + notification tokens.
3. Test connection, then enable.

Only use Live for real payments.

### Verify in the app

Use **Settings → Test connection** (`POST /api/settings/test`). It calls Tamara `GET /checkout/payment-types` with your configured currency.

If test fails with "Merchant is not found", the token/environment pair is wrong.

### Supported currencies

Tamara typically supports **SAR**, **AED**, and other GCC currencies depending on your merchant account. Your checkout currency must be in the Tamara currency allowlist configured in Settings.

### Webhook URL

Tamara notification URL saved for the store:

```
{APP_URL}/webhooks/tamara
```

`APP_URL` must be publicly reachable (ngrok in local dev) for Tamara to send `order_approved` webhooks.

Tamara `POST /checkout` requires `tax_amount` and `shipping_amount` (use `0` when not applicable). A missing pair returns HTTP 500: *"Something went wrong with us."*
