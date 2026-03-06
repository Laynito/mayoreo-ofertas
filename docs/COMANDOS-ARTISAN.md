# Lista de comandos Artisan (Mayoreo Cloud / Cazador de Ofertas)

Documentación de todos los comandos personalizados del proyecto para backup y referencia.

---

## Browsershot (capturas de pantalla)

### `php artisan browsershot:verificar`
**Para qué sirve:** Prueba si Browsershot (Puppeteer/Chromium) puede tomar una captura en el servidor. Si falla por librerías faltantes (ej. `libatk-1.0.so.0`), indica que ejecutes el script de instalación de dependencias.

**Cuándo usarlo:** Tras instalar el servidor o cuando las bajadas históricas llegan a Telegram sin captura ("Captura no disponible").

**Ejemplo:**
```bash
php artisan browsershot:verificar
```

---

## Telegram

### Canales Gratis y Premium
Las ofertas se reparten así: **Gratis** = ofertas 10–39 % (y teasers de “bomba”); **Premium** = ofertas ≥40 %, <10 % y bajadas históricas con captura.  
**Importante:** En el `.env` deben estar definidos **los dos**: `TELEGRAM_CHAT_ID_FREE` y `TELEGRAM_CHAT_ID_PREMIUM`. Si `TELEGRAM_CHAT_ID_PREMIUM` está vacío, las ofertas Premium no se envían a ningún lado (no se usa `TELEGRAM_CHAT_ID` como sustituto, para no mandar por error al canal Gratis). Comprueba que los IDs no estén intercambiados: `telegram:verificar --test` envía un mensaje a cada canal para confirmar.

### `php artisan telegram:verificar`
**Para qué sirve:** Comprueba que `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID_FREE` y `TELEGRAM_CHAT_ID_PREMIUM` estén definidos en el `.env`. Si algún canal está vacío, las ofertas no se enviarán ahí.

**Opciones:**
- `--test` — Envía un mensaje de prueba a cada canal configurado (Free y Premium) para verificar que el bot puede publicar.

**Ejemplo:**
```bash
php artisan telegram:verificar
php artisan telegram:verificar --test
```

### `php artisan telegram:limpiar-mensajes-antiguos`
**Para qué sirve:** Borra de Telegram los mensajes de oferta con más de 24 horas (usa `deleteMessage`). Cada oferta enviada guarda su `message_id` en la tabla `telegram_mensajes_oferta`; este comando elimina esos mensajes del canal y los registros de la BD para mantener Premium y Gratis con solo ofertas vigentes.

**Opciones:**
- `--horas=24` — Mensajes con más de esta antigüedad (en horas) se borran. Por defecto 24.

**Programación:** Se ejecuta **diariamente** vía `Schedule` (cron).

**Ejemplo:**
```bash
php artisan telegram:limpiar-mensajes-antiguos
php artisan telegram:limpiar-mensajes-antiguos --horas=12
```

---

## Ofertas y bajadas de precio

### `php artisan ofertas:procesar-bajadas`
**Para qué sirve:** Evalúa productos con historial de precios y, si la bajada es ≥10 %, notifica por Telegram según calidad: ≥30 % → canal Premium (con captura Browsershot si está disponible); 10–29,9 % → canal Gratis (solo texto). Respeta `permite_descuento_adicional` y no reenvía la misma bajada (usa `bajada_notificada_at` en historial).

**Opciones:**
- `--sync` — Ejecuta el procesamiento en sincróno (en el mismo proceso) en lugar de encolar un job.
- `--productos=1,2,3` — Limita la evaluación a los IDs de producto indicados (separados por coma).

**Ejemplos:**
```bash
php artisan ofertas:procesar-bajadas
php artisan ofertas:procesar-bajadas --sync
php artisan ofertas:procesar-bajadas --productos=3819,3809 --sync
```

**Programación:** Se ejecuta automáticamente cada 5 minutos vía Scheduler (`routes/console.php`).

---

### `php artisan ofertas:limpiar-capturas`
**Para qué sirve:** Borra archivos `.png` en `storage/app/public/capturas/` que tengan más de 24 horas. Evita llenar el servidor con capturas antiguas de Browsershot (cuando se guardan ahí).

**Ejemplo:**
```bash
php artisan ofertas:limpiar-capturas
```

**Programación:** Se ejecuta automáticamente cada día a medianoche (`daily()` en `routes/console.php`).

---

## Rastreo de tiendas

### `php artisan rastreo:todas`
**Para qué sirve:** Rastrea ofertas de todas las tiendas configuradas (Calimax, Sams Club, Costco, Coppel, Elektra, Amazon, Mercado Libre, Walmart, AliExpress, Office Depot), en ese orden. Actualiza productos, registra historial de precios y encola jobs para enviar ofertas a Telegram (canal Free/Premium según %). Pausa de 10 s entre tiendas. Al final de cada tienda envía resumen a Telegram si está configurado.

**Opciones:**
- `--max=N` — Límite de productos por tienda (útil para pruebas rápidas).
- `--notificar-todos` — Encola todas las ofertas con descuento; por defecto solo nuevas o actualizadas.

**Ejemplos:**
```bash
php artisan rastreo:todas
php artisan rastreo:todas --max=10
php artisan rastreo:todas --notificar-todos
```

**Programación:** Se ejecuta cada hora vía Scheduler (`hourly()`), con `withoutOverlapping(120)` y `onOneServer()`.

---

### `php artisan rastreo:tienda {tienda}`
**Para qué sirve:** Rastrea ofertas de una sola tienda. El argumento `tienda` es el nombre (ej. Coppel, Walmart, Calimax).

**Opciones:** Las que use el comando base (p. ej. `--max` si la tienda lo soporta).

**Ejemplo:**
```bash
php artisan rastreo:tienda Coppel
php artisan rastreo:tienda "Mercado Libre"
```

---

### `php artisan rastreo:tienda-calimax`
**Para qué sirve:** Rastrea ofertas de Calimax (Tijuana / Baja California).

**Opciones:**
- `--max=N` — Procesar solo los primeros N productos.

**Ejemplo:**
```bash
php artisan rastreo:tienda-calimax
php artisan rastreo:tienda-calimax --max=20
```

---

### `php artisan rastreo:tienda-sams`
**Para qué sirve:** Rastrea ofertas de Sam's Club México.

**Opciones:**
- `--max=N` — Procesar solo los primeros N productos.

---

### `php artisan rastreo:tienda-costco`
**Para qué sirve:** Rastrea ofertas de Costco México.

**Opciones:**
- `--max=N` — Procesar solo los primeros N productos.

---

### `php artisan rastreo:limpiar-tienda {tienda}`
**Para qué sirve:** Borra todos los productos y el historial de precios de una tienda (o de todas). Útil para “empezar de cero” con una tienda y volver a subir ofertas desde el rastreo.

**Argumento:**
- `tienda` — Nombre de la tienda (ej. Coppel, Walmart) o `all` / `todo` / `*` para todas.

**Opciones:**
- `--force` — No pedir confirmación.

**Ejemplos:**
```bash
php artisan rastreo:limpiar-tienda Coppel
php artisan rastreo:limpiar-tienda all --force
```

---

## Resumen de programación (Scheduler)

| Comando                     | Frecuencia   | Notas                          |
|----------------------------|-------------|---------------------------------|
| `rastreo:todas`            | Cada hora   | Sin solapamiento 120 min        |
| `ofertas:procesar-bajadas` | Cada 5 min  | —                               |
| `ofertas:limpiar-capturas` | Diario 00:00| —                               |

Para que el Scheduler funcione, el cron debe ejecutar cada minuto:

```bash
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

---

## Comandos estándar de Laravel (útiles)

- `php artisan config:clear` — Limpia la caché de configuración (útil tras cambiar `.env`).
- `php artisan cache:clear` — Limpia la caché de la aplicación.
- `php artisan queue:work` — Procesa jobs de la cola (en producción suele usarse Supervisor).
- `php artisan queue:failed` — Lista jobs fallidos.
- `php artisan migrate` — Ejecuta migraciones.
- `php artisan migrate:status` — Estado de las migraciones.

---

*Última actualización: marzo 2026.*
