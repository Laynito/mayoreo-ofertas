# Crontab del servidor (mayoreo-cloud) — como estaba configurado ayer

Copia este bloque en el crontab del VPS (`crontab -e` como root o el usuario que corresponda):

```cron
# 1. Mantenimiento y Backups (Ya los tienes, solo los agrupamos)
0 3 * * * sudo clp-update
0 4 * * * /root/backup-auto.sh >> /var/log/backup.log 2>&1
0 21 * * * /home/mayoreo/backups/backup_database.sh
0 3 * * 0 /home/mayoreo/backups/backup_files.sh

# 2. Motores de Laravel (Cada minuto, correcto)
* * * * * cd /home/mayoreo/htdocs/mayoreo-cloud && php artisan schedule:run >> /dev/null 2>&1

# === SCRAPERS (Recolección de datos) ===
# Laravel Schedule (schedule:run cada minuto) ya incluye:
#   app:run-scraper cada 10 min → ML, Walmart, Coppel, Elektra (según marketplaces activos)
#   productos:sync-affiliate --send-telegram y queue:work
# Elektra y el resto van por ahí; no hace falta cron aparte para cada scraper.
# (Opcional) Coppel directo cada 6 h si quieres ejecutarlo aparte:
# 0 */6 * * * .../python/scraper_coppel.py >> .../storage/logs/scrapers.log 2>&1

# Facebook y Telegram
0 10,14,18,21 * * * /home/mayoreo/htdocs/mayoreo-cloud/python/venv/bin/python3 /home/mayoreo/htdocs/mayoreo-cloud/python/facebook_publisher.py >> /home/mayoreo/htdocs/mayoreo-cloud/storage/logs/facebook_bot.log 2>&1
```

## Aplicar en el servidor

```bash
crontab -e
# Pega el bloque de arriba (sin los marcadores ```cron y ```), guarda y sale.

# Verificar:
crontab -l
```

## Logs

- Scraper Coppel: `storage/logs/scrapers.log`
- Facebook + Telegram: `storage/logs/facebook_bot.log`

## Cuándo se envían ofertas a Telegram

Los productos **se envían a Telegram** en estos momentos:

1. **Tras el scraper unificado** (`php artisan app:run-scraper`):
   - El comando ejecuta los scrapers (ML, Walmart, Coppel, Elektra), luego llama a `productos:sync-affiliate --send-telegram`.
   - Ese comando genera `url_afiliado` para productos nuevos y **encola un job por producto** para enviarlo a Telegram (con retraso de 8 s entre cada uno para no saturar).
   - Después, `queue:work --stop-when-empty` procesa la cola y **envía los mensajes** mediante `ProcessTelegramPost` y `TelegramService`.

2. **Orden de envío:** primero marketplaces con afiliados (ML, Coppel), luego el resto (Walmart, Elektra). Solo se envía una oferta por URL (se evitan duplicados).

3. **Envío manual:** desde el panel Filament (Recursos → Productos) puedes usar la acción **«Enviar a Telegram»** en un producto. También existe `php artisan telegram:send-varied` para enviar ofertas variadas que no se hayan enviado recientemente.

**Requisito:** la cola debe estar procesándose. En el servidor suele correr `php artisan queue:work` (o un supervisor que lo mantenga). Si no hay worker, los jobs se quedan en la tabla `jobs` hasta que se ejecute `queue:work`.

### Si solo ejecutas scrapers a mano (Python)

Si ejecutas **solo** los scripts de Python (por ejemplo `python/venv/bin/python python/scraper_elektra.py` o `scraper_coppel.py`), los productos se guardan en la base de datos pero **no se encolan para Telegram**. Para que entren a la cola y se envíen:

1. **Opción recomendada:** usar el ciclo completo desde Laravel:
   ```bash
   php artisan app:run-scraper
   ```
   Eso ejecuta todos los scrapers (ML, Walmart, Coppel, Elektra), luego `productos:sync-affiliate --send-telegram` y finalmente `queue:work --stop-when-empty`.

2. **Si ya corriste los scrapers a mano:** después de que terminen, ejecuta:
   ```bash
   php artisan productos:sync-affiliate --send-telegram
   php artisan queue:work --stop-when-empty
   ```
   El primer comando toma los productos que aún no tienen `url_afiliado`, les genera el enlace de afiliado y **encola un job por cada uno** para Telegram (con 8 s de retraso entre mensajes). El segundo procesa la cola y envía los mensajes.

**Orden de envío a Telegram:** primero Mercado Libre y Coppel (si tienen afiliados), luego Walmart, luego Elektra. Por cada URL de producto solo se envía un mensaje (se evitan duplicados).
