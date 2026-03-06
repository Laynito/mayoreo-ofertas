<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Despliegue en Linux (VPS)
    |--------------------------------------------------------------------------
    | Instala dependencias: libgbm-dev libnss3 libatk-bridge2.0-0 libgtk-3-0 libasound2
    | El código usa --no-sandbox y --disable-setuid-sandbox (NotificadorTelegram).
    | Ver docs/BROWSERSHOT-LINUX.md para más detalles.
    */

    /*
    |--------------------------------------------------------------------------
    | Ruta a Chrome/Chromium (opcional)
    |--------------------------------------------------------------------------
    | Si Puppeteer no encuentra Chrome (Could not find Chrome ver. x.x), instala
    | Chromium del sistema (apt install chromium) y define aquí la ruta al binario.
    | Ejemplo: /usr/bin/chromium o /usr/bin/chromium-browser
    */
    'chrome_path' => env('BROWSERSHOT_CHROME_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Timeout captura de pantalla (segundos)
    |--------------------------------------------------------------------------
    | Browsershot/Puppeteer: tiempo máximo para cargar la página y tomar la captura.
    | Aumentar si las tiendas cargan lento o hay mucho JS. Por defecto 45s para más consistencia.
    */
    'timeout' => (int) env('BROWSERSHOT_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Ancho y alto de ventana (píxeles)
    |--------------------------------------------------------------------------
    */
    'ancho' => (int) env('BROWSERSHOT_ANCHO', 1280),
    'alto' => (int) env('BROWSERSHOT_ALTO', 800),

    /*
    |--------------------------------------------------------------------------
    | Espera antes de buscar popups (milisegundos)
    |--------------------------------------------------------------------------
    | Tiempo de espera tras cargar la página antes de buscar modales (p. ej.
    | "Ciudad de entrega" en Coppel). Así el modal tiene tiempo de renderizarse.
    */
    'delay_antes_popups' => (int) env('BROWSERSHOT_DELAY_ANTES_POPUPS', 2500),

    /*
    |--------------------------------------------------------------------------
    | Espera tras cerrar el último popup (milisegundos)
    |--------------------------------------------------------------------------
    | Pausa dentro del script después de cerrar popups (o si no hay ninguno)
    | antes de tomar la captura, para que el modal desaparezca del DOM.
    */
    'delay_tras_cierre_popup' => (int) env('BROWSERSHOT_DELAY_TRAS_CIERRE_POPUP', 800),

    /*
    |--------------------------------------------------------------------------
    | CSS para ocultar elementos en la captura
    |--------------------------------------------------------------------------
    | Se inyecta con addStyleTag antes de la captura. Oculta modales (ubicación),
    | backdrops y widgets de chat para que el screenshot se vea más limpio.
    */
    'css_ocultar_elementos' => implode(' ', [
        '[role="dialog"], [role="alertdialog"], .modal-backdrop, .modal, [class*="Modal"], [class*="modal"]',
        ', #chat-widget-container, [id*="chat-widget"], [class*="chat-widget"], [class*="ChatWidget"]',
        '{ display: none !important; visibility: hidden !important; }',
    ]),
];
