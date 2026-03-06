<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timeout descarga de imágenes (Telegram)
    |--------------------------------------------------------------------------
    | CDNs como Coppel pueden ser lentos. Aumentar a 20-30s evita fallos al
    | enviar ofertas con foto. En segundos.
    */
    'timeout_imagen_telegram' => (int) env('RASTREADOR_TIMEOUT_IMAGEN_TELEGRAM', 35),

    /*
    |--------------------------------------------------------------------------
    | Rotación de User-Agent
    |--------------------------------------------------------------------------
    | Lista de User-Agents reales (Chrome/Safari) para rotar y reducir
    | detección por tiendas que bloquean scrapers (Walmart, MercadoLibre).
    */
    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reintentos con exponential backoff
    |--------------------------------------------------------------------------
    */
    'reintentos_maximos' => (int) env('RASTREADOR_REINTENTOS', 3),
    'backoff_base_segundos' => (int) env('RASTREADOR_BACKOFF_BASE', 2),
];
