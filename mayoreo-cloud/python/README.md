# Fase 1: Motor Python - Scraper Mercado Libre

## Entorno virtual y dependencias

Desde la raíz del proyecto (o desde `python/`):

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud/python
python3 -m venv venv
source venv/bin/activate   # Linux/macOS
# En Windows: venv\Scripts\activate
pip install -r requirements.txt
```

## Credenciales MySQL (Laravel .env)

El script lee **DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD** del `.env` de Laravel (incl. contraseñas con `#` o `$` si van entre comillas).

## Instalar Playwright y ejecutar el scraper

En el servidor usar **python3** (no `python`). Si no existe el venv, créalo primero:

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud/python
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
playwright install chromium
```

Luego, para scrapear:

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud/python
source venv/bin/activate
python3 scraper_ml.py
```

(Scrape en vivo: 2 secciones — ofertas generales + relámpago.)

Otra URL o ver el navegador:

```bash
HEADLESS=0 ML_SEARCH_URL="https://listado.mercadolibre.com.mx/tu-busqueda" python scraper_ml.py
```

## Después del scraper: afiliado y Telegram (Laravel)

Los comandos de Laravel (`php artisan ...`) deben ejecutarse **desde la raíz del proyecto**, no desde `python/`:

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud

# Rellenar url_afiliado y encolar envío a Telegram
php artisan productos:sync-affiliate --send-telegram

# Procesar la cola (envíos a Telegram)
php artisan queue:work
```

**Si `queue:work` no funciona:**

1. **"Could not open input file: artisan"** → Estás en la carpeta equivocada. Entra a la raíz del proyecto:
   ```bash
   cd /home/mayoreo/htdocs/mayoreo-cloud
   php artisan queue:work
   ```
2. **El comando no hace nada** → Es normal: el worker queda esperando jobs. Para procesar solo un job y salir: `php artisan queue:work --once`.
3. **Ver jobs pendientes:** en MySQL tabla `jobs`. Asegúrate de que en `.env` tengas `QUEUE_CONNECTION=database` y que exista la tabla `jobs` (migraciones ejecutadas).
