<?php

return [
    'proxy' => env('PERSIAN_PROVIDERS_PROXY'),

    // Seconds. Any provider below can override these with its own 'timeout' and 'connect_timeout'.
    'timeout' => env('PERSIAN_PROVIDERS_TIMEOUT', 15),
    'connect_timeout' => env('PERSIAN_PROVIDERS_CONNECT_TIMEOUT', 5),

    'payment' => [
        'default' => env('PERSIAN_PROVIDERS_PAYMENT', 'zarinpal'),

        'zarinpal' => [
            'token' => env('ZARINPAL_TOKEN', ''),
            'sandbox' => env('ZARINPAL_SANDBOX', false),
        ],

        'zibal' => [
            'token' => env('ZIBAL_TOKEN', ''),
        ],

        'idpay' => [
            'token' => env('IDPAY_TOKEN', ''),
            'sandbox' => env('IDPAY_SANDBOX', false),
        ],

        'sep' => [
            'terminal_id' => env('SEP_TERMINAL_ID', ''),
            'use_get_method' => env('SEP_USE_GET_METHOD', false),
            'use_neo_ipg' => env('SEP_USE_NEO_IPG', false),
        ],

        'sepehr' => [
            'terminal_id' => env('SEPEHR_TERMINAL_ID', ''),
            'use_get_method' => env('SEPEHR_USE_GET_METHOD', false),
        ],

        'nextpay' => [
            'token' => env('NEXTPAY_TOKEN', ''),
        ],

        'aqayepardakht' => [
            'pin' => env('AQAYEPARDAKHT_PIN', ''),
            'sandbox' => env('AQAYEPARDAKHT_SANDBOX', false),
        ],

        'panapal' => [
            'merchant_id' => env('PANAPAL_MERCHANT_ID', ''),
        ],

        'payping' => [
            'token' => env('PAYPING_TOKEN', ''),
        ],

        'paystar' => [
            'gateway_id' => env('PAYSTAR_GATEWAY_ID', ''),
            'sign_key' => env('PAYSTAR_SIGN_KEY', ''),
        ],

        'vandar' => [
            'token' => env('VANDAR_TOKEN', ''),
        ],

        'pasargad' => [
            'username' => env('PASARGAD_USERNAME', ''),
            'password' => env('PASARGAD_PASSWORD', ''),
            'terminal_id' => env('PASARGAD_TERMINAL_ID', ''),
        ],

        'sadad' => [
            'merchant_id' => env('SADAD_MERCHANT_ID', ''),
            'terminal_id' => env('SADAD_TERMINAL_ID', ''),
            'key' => env('SADAD_KEY', ''),
        ],

        'damavand' => [
            'terminal_id' => env('DAMAVAND_TERMINAL_ID', ''),
            'key' => env('DAMAVAND_KEY', ''),
        ],
    ],

    'sms' => [
        'default' => env('PERSIAN_PROVIDERS_SMS', 'melipayamak'),

        'kavenegar' => [
            'token' => env('KAVENEGAR_TOKEN', ''),
            'line' => env('KAVENEGAR_LINE', ''),
        ],

        'smsir' => [
            'token' => env('SMSIR_TOKEN', ''),
            'line' => env('SMSIR_LINE', ''),
        ],

        'ghasedak' => [
            'token' => env('GHASEDAK_TOKEN', ''),
            'line' => env('GHASEDAK_LINE', ''),
        ],

        'sabanovin' => [
            'token' => env('SABANOVIN_TOKEN', ''),
            'line' => env('SABANOVIN_LINE', ''),
        ],

        'melipayamak' => [
            'username' => env('MELIPAYAMAK_USERNAME', ''),
            'password' => env('MELIPAYAMAK_PASSWORD', ''),
            'line' => env('MELIPAYAMAK_LINE', ''),
        ],

        'farapayamak' => [
            'username' => env('FARAPAYAMAK_USERNAME', ''),
            'password' => env('FARAPAYAMAK_PASSWORD', ''),
            'line' => env('FARAPAYAMAK_LINE', ''),
        ],
    ],

    'inquiry' => [
        'default' => env('PERSIAN_PROVIDERS_INQUIRY', 'zohal'),

        'zohal' => [
            'token' => env('ZOHAL_TOKEN', ''),
        ],

        'jibit' => [
            'api_key' => env('JIBIT_API_KEY', ''),
            'secret_key' => env('JIBIT_SECRET_KEY', ''),
        ],
    ],

    'exchange' => [
        'default' => env('PERSIAN_PROVIDERS_EXCHANGE', 'nobitex'),

        'nobitex' => [
            'token' => env('NOBITEX_TOKEN', ''),
        ],

        'bitpin' => [
            'token' => env('BITPIN_TOKEN', ''),
        ],

        'wallex' => [
            'token' => env('WALLEX_TOKEN', ''),
        ],
    ],
];
