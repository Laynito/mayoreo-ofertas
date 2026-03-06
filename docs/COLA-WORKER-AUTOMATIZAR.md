# Automatizar el worker de cola (ofertas a Telegram)

## Por qué no está automatizado por defecto

El **rastreo** sí está automatizado: el cron ejecuta cada hora `php artisan schedule:run`, que lanza `rastreo:todas` y `ofertas:procesar-bajadas`. Esos comandos **encolan** jobs (por ejemplo `EnviarOfertaTelegramJob`) en la tabla `jobs`.

Para que esos jobs se ejecuten (y las ofertas lleguen a Telegram), tiene que estar corriendo un **proceso que consuma la cola**: `php artisan queue:work`. Ese proceso es de larga duración y no forma parte del cron; hay que levantarlo como servicio (Supervisor o systemd). Si no hay ningún worker corriendo, los jobs se quedan en la cola y las ofertas no se envían.

## Cómo automatizarlo con Supervisor

1. **Instala Supervisor** (si no lo tienes):
   ```bash
   sudo apt install supervisor   # Debian/Ubuntu
   ```

2. **Copia y ajusta la configuración** del repo a Supervisor:
   ```bash
   sudo cp /ruta/a/mayoreo-cloud/deploy/supervisor-laravel-queue.conf /etc/supervisor/conf.d/laravel-queue.conf
   ```
   Edita el archivo y sustituye **todas** las apariciones de `/ruta/a/mayoreo-cloud` por la ruta real del proyecto (ej. `/home/mayoreo/htdocs/mayoreo-cloud`). Ajusta `user=` si el servidor web no corre como `www-data`.

3. **Recarga y arranca el worker**:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start laravel-queue:*
   ```

4. **Comprueba** que el worker está en marcha:
   ```bash
   sudo supervisorctl status
   ```
   Debes ver `laravel-queue:laravel-queue_00   RUNNING`.

A partir de ahí, el worker se inicia con el servidor y se reinicia solo si se cae. Las ofertas encoladas por el rastreo se procesarán y llegarán a Telegram (si tienes configurados `TELEGRAM_CHAT_ID_PREMIUM` y/o `TELEGRAM_CHAT_ID_FREE` en `.env`).

## Sin Supervisor (solo para pruebas)

En desarrollo puedes dejar un worker manual en una terminal:
```bash
php artisan queue:work database --sleep=3 --tries=3
```
Al cerrar la terminal se deja de procesar la cola.
