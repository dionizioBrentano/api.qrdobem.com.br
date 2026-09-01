<?php

return [
    'mode' => env('MERCADOPAGO_MODE', 'test'),
    'access_token_test' => env('MERCADOPAGO_ACCESS_TOKEN_TEST', ''),
    'access_token_prod' => env('MERCADOPAGO_ACCESS_TOKEN_PROD', ''),
    'public_key_test' => env('MERCADOPAGO_PUBLIC_KEY_TEST', ''),
    'public_key_prod' => env('MERCADOPAGO_PUBLIC_KEY_PROD', ''),
    'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET', ''),
    'frontend_url' => env('FRONTEND_URL', 'https://qrdobem.com.br'),

    'defaults' => [
        'unit_price' => env('CREDITS_UNIT_PRICE', 1.00),
        'min_qty' => env('CREDITS_MIN_QTY', 1),
        'max_qty' => env('CREDITS_MAX_QTY', 500),
    ],

    'base_url' => rtrim(env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'), '/'),
];
