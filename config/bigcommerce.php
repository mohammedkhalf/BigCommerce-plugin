<?php

return [
    'client_id' => env('BIGCOMMERCE_CLIENT_ID'),
    'client_secret' => env('BIGCOMMERCE_CLIENT_SECRET'),
    'app_id' => env('BIGCOMMERCE_APP_ID'),
    'auth_callback' => env('BIGCOMMERCE_AUTH_CALLBACK', env('APP_URL').'/auth/install'),
    'oauth_url' => env('BIGCOMMERCE_OAUTH_URL', 'https://login.bigcommerce.com/oauth2/token'),
    'api_url' => env('BIGCOMMERCE_API_URL', 'https://api.bigcommerce.com'),
    'paid_status_id' => (int) env('BIGCOMMERCE_PAID_STATUS_ID', 11),
    'checkout_script_sri' => env('BIGCOMMERCE_CHECKOUT_SCRIPT_SRI'),
    'jwt_issuer' => env('BIGCOMMERCE_JWT_ISSUER', 'bc'),
    'app_jwt_secret' => env('APP_SESSION_JWT_SECRET', env('APP_KEY')),
    'app_jwt_ttl' => 60 * 60 * 12,
];
