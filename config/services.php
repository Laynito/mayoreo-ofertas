<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'admitad' => [
        'id' => env('ADMITAD_SUBID', ''),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'chat_id_free' => env('TELEGRAM_CHAT_ID_FREE'),
        'chat_id_premium' => env('TELEGRAM_CHAT_ID_PREMIUM'),
        'premium_join_url' => env('TELEGRAM_PREMIUM_JOIN_URL', ''),
        // Límite de ofertas por tienda que se envían al canal Gratis en cada rastreo. Premium recibe todas. 0 = sin límite.
        'max_ofertas_por_rastreo' => (int) env('TELEGRAM_MAX_OFERTAS_POR_RASTREO', 0),
        // Segundos de espera entre cada oferta encolada (envío poco a poco; reduce timeouts y carga de Browsershot). Recomendado 15–30.
        'delay_entre_ofertas_segundos' => (int) env('TELEGRAM_DELAY_ENTRE_OFERTAS', 15),
        // Segundos de espera antes de la primera oferta (evita que la 1ª captura falle por arranque en frío de Chromium).
        'delay_inicial_ofertas_segundos' => (int) env('TELEGRAM_DELAY_INICIAL_OFERTAS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy global (rastreo)
    |--------------------------------------------------------------------------
    | PROXY_URL: si está vacío, todo el tráfico va directo; si tiene URL, todo pasa por ahí.
    | Útil para gastar en proxy solo cuando una tienda bloquee. Ejemplo: http://user:pass@host:8080
    */
    'proxy_url' => env('PROXY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Proxies por tienda (rastreo) – legacy; preferir PROXY_URL para todo
    |--------------------------------------------------------------------------
    */
    'walmart' => [
        'proxy' => env('WALMART_HTTP_PROXY'),
    ],
    'amazon' => [
        'proxy' => env('AMAZON_HTTP_PROXY'),
    ],
    'mercado_libre' => [
        'proxy' => env('MERCADOLIBRE_HTTP_PROXY'),
        'app_id' => env('ML_APP_ID'),
        'secret_key' => env('ML_SECRET_KEY'),
        'redirect_uri' => env('ML_REDIRECT_URI'),
        'affiliate_id' => env('ML_AFFILIATE_ID'),
    ],
    'elektra' => [
        'proxy' => env('ELEKTRA_HTTP_PROXY'),
        'codigo_postal' => env('ELEKTRA_CODIGO_POSTAL', '01210'),
    ],
    'coppel' => [
        'proxy' => env('COPPEL_HTTP_PROXY'),
    ],
    'liverpool' => [
        'proxy' => env('LIVERPOOL_HTTP_PROXY'),
    ],
    'bodega_aurrera' => [
        'proxy' => env('BODEGAAURRERA_HTTP_PROXY'),
    ],
    'chedraui' => [
        'proxy' => env('CHEDRAUI_HTTP_PROXY'),
    ],
    'soriana' => [
        'proxy' => env('SORIANA_HTTP_PROXY'),
    ],
    'costco' => [
        'proxy' => env('COSTCO_HTTP_PROXY'),
    ],
    'sams_club' => [
        'proxy' => env('SAMSCLUB_HTTP_PROXY'),
    ],
    'calimax' => [
        'proxy' => env('CALIMAX_HTTP_PROXY'),
    ],
    'aliexpress' => [
        'proxy' => env('ALIEXPRESS_HTTP_PROXY'),
    ],
    'office_depot' => [
        'proxy' => env('OFFICE_DEPOT_HTTP_PROXY'),
    ],

];
