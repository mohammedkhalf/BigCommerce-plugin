# BigCommerce Marketplace assets

## Included

- `icon-200.png` — 200×200
- `logo-primary.png` — 350×130 (175×65 aspect ratio)
- `logo-alternate.png` — 518×316 (259×158 aspect ratio)
- `screenshot-1-onboarding.png` — 3840×2160
- `screenshot-2-dashboard.png` — 3840×2160
- `screenshot-3-payments.png` — 3840×2160
- `screenshot-4-help.png` — 3840×2160

The screenshots exceed BigCommerce's 1280×720 minimum and keep a 16:9 aspect ratio.

## Reproducing screenshots

In a local environment:

```bash
php artisan serve
```

Open these local-only routes and capture the viewport at 1280×720 or higher:

- `/marketplace-preview/onboarding`
- `/marketplace-preview/dashboard`
- `/marketplace-preview/payments`
- `/marketplace-preview/help`

The routes return 404 outside the `local` environment. Their sample payments are presentation-only and never enter the application database.

## Before submission

These logo files are draft artwork. Replace them with Tamara's approved brand assets after trademark/brand review, while preserving the required dimensions and filenames.

Replace `support@example.com` in preview data and all deployment configuration with the approved support contact. Do not include merchant credentials, customer data, or production payment references in screenshots.
