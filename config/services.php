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

    'mercadolibre' => [
        'app_id' => env('ML_APP_ID'),
        'affid' => env('ML_AFFID', env('ML_APP_ID')), // ID afiliado; por defecto mismo que app_id
        'matt_word' => env('ML_MATT_WORD', 'mayoreo_cloud'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'user_id' => env('ML_USER_ID'),
        'site_id' => env('ML_SITE_ID', 'MLM'),
        'redirect_uri' => env('ML_REDIRECT_URI'),
        'refresh_token' => env('ML_REFRESH_TOKEN'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id_free' => env('TELEGRAM_CHAT_ID_FREE'),
    ],

    'facebook' => [
        'page_id' => env('FB_PAGE_ID'),
        'page_access_token' => env('FB_PAGE_ACCESS_TOKEN'),
        'graph_version' => env('FB_GRAPH_VERSION', 'v20.0'),
    ],

    /*
     * Admitad: red de afiliados. Credenciales en cuenta Admitad → Webmaster → Configuración → Mostrar credenciales.
     */
    'admitad' => [
        'client_id' => env('ADMITAD_CLIENT_ID'),
        'client_secret' => env('ADMITAD_CLIENT_SECRET'),
        'scope' => env('ADMITAD_SCOPE', 'advcampaigns advcampaigns_for_website banners websites private_data coupons deeplink_generator short_link'),
    ],

];
