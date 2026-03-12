# Fase 1: Motor Python - Scraper Mercado Libre

## En el VPS (Debian/Ubuntu): si no existe `pip` ni `python`

Instala pip y opcionalmente el alias `python`:

```bash
sudo apt update
sudo apt install -y python3-pip python3-venv
# Opcional: sudo apt install -y python-is-python3
```

Luego usa **siempre** `python3` y `pip3` (o un venv con `pip`).

## Entorno virtual y dependencias

Desde la raíz del proyecto (o desde `python/`):

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud
python3 -m venv python/venv
source python/venv/bin/activate   # Linux/macOS
# En Windows: python\venv\Scripts\activate
pip install -r python/requirements.txt
```

## Credenciales MySQL (Laravel .env)

El script lee **DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD** del `.env` de Laravel (incl. contraseñas con `#` o `$` si van entre comillas).

## Instalar Playwright y ejecutar el scraper

Si no existe el venv, créalo primero:

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud/python
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
playwright install chromium
```

Luego, para scrapear:

**Importante:** desde la **raíz del proyecto** usa el Python del venv como comando (no ejecutes `python3 python/venv/bin/python` — eso da error "source code cannot contain null bytes"):

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud
python/venv/bin/python python/scraper_ml.py
python/venv/bin/python python/walmart_sitemap_scraper.py   # Walmart: sitemap (sin navegador, recomendado)
python/venv/bin/python python/scraper_coppel.py
python/venv/bin/python python/scraper_elektra.py
python/venv/bin/python python/scraper_sams.py
python/venv/bin/python python/scraper_bodega_aurrera.py
```

O desde `python/` con el venv activado:

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud/python
source venv/bin/activate
python scraper_ml.py
python walmart_sitemap_scraper.py   # Walmart (solo sitemap)
# etc.
```

**Walmart en VPS (la vía estable):** sitemap scraper. No abre navegador, no dispara PerimeterX. Las URLs deben ser índices de ofertas (Ofertas Flash, Liquidación, Ahorros Especiales).

```bash
cd /home/mayoreo/htdocs/mayoreo-cloud
WALMART_OFERTAS_URLS="https://www.walmart.com.mx/shop/ofertas-flash-walmart" python/venv/bin/python python/walmart_sitemap_scraper.py
```

O con varias URLs en `.env` o en Filament → Marketplaces → Walmart:
- Ofertas Flash: `https://www.walmart.com.mx/shop/ofertas-flash-walmart`
- Liquidación: `https://www.walmart.com.mx/browse/especiales/ofertas/360013_300279_300286`
- Ahorros Especiales: `https://www.walmart.com.mx/browse/especiales/ahorros-especiales/360013_300279`

Otra URL o ver el navegador:

```bash
HEADLESS=0 ML_SEARCH_URL="https://listado.mercadolibre.com.mx/tu-busqueda" python3 scraper_ml.py
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
