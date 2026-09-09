<?php

return [
    'client_id' => env('BIGCOMMERCE_CLIENT_ID'),
    'client_secret' => env('BIGCOMMERCE_CLIENT_SECRET'),
    'app_id' => env('BIGCOMMERCE_APP_ID'),
    'auth_callback' => env('BIGCOMMERCE_AUTH_CALLBACK', env('APP_URL').'/auth/install'),
    'oauth_url' => env('BIGCOMMERCE_OAUTH_URL', 'https://login.bigcommerce.com/oauth2/token'),
    'api_url' => env('BIGCOMMERCE_API_URL', 'https://api.bigcommerce.com'),
    'paid_status_id' => (int) env('BIGCOMMERCE_PAID_STATUS_ID', 11),
    'shipped_status_id' => (int) env('BIGCOMMERCE_SHIPPED_STATUS_ID', 2),
    'refunded_status_id' => (int) env('BIGCOMMERCE_REFUNDED_STATUS_ID', 4),
    'partially_refunded_status_id' => (int) env('BIGCOMMERCE_PARTIALLY_REFUNDED_STATUS_ID', 14),
    'checkout_script_sri' => env('BIGCOMMERCE_CHECKOUT_SCRIPT_SRI'),
    'jwt_issuer' => env('BIGCOMMERCE_JWT_ISSUER', 'bc'),
    'app_jwt_secret' => env('APP_SESSION_JWT_SECRET', env('APP_KEY')),
    'app_jwt_ttl' => 60 * 60 * 12,

    /*
    |--------------------------------------------------------------------------
    | Required OAuth scopes
    |--------------------------------------------------------------------------
    |
    | Enable these in BigCommerce DevTools for your app, then reinstall on each
    | store so a new access token is issued with the updated scopes.
    |
    */
    'oauth_scopes' => [
        'checkout' => [
            'checkouts' => ['store_checkout', 'store_checkouts'],
        ],
        'install' => [
            'checkouts' => ['store_checkout', 'store_checkouts'],
            'checkout_content' => ['store_content_checkout'],
            'content' => ['store_v2_content'],
            'orders' => ['store_v2_orders'],
            'information' => ['store_v2_information', 'store_v2_information_read_only'],
        ],
    ],
];
