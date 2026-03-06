# Browsershot (Puppeteer) en Linux / VPS

Para que las capturas de pantalla de ofertas y bajadas históricas funcionen en un servidor Linux (Ubuntu/Debian), instala Chromium (o Chrome) y las dependencias de sistema.

**Si Chrome/Chromium no está instalado:** Browsershot fallará y en Telegram verás "(Captura no disponible)". El sistema intentará usar la **imagen del listado** (`imagen_url` del producto) cuando exista; si no, solo texto. Para tener capturas reales de la página del producto, instala Chromium y configura `BROWSERSHOT_CHROME_PATH` (ver sección 0).

## 0. Error "Could not find Chrome (ver. x.x)"

Si en los logs aparece **"Could not find Chrome"** o **"cache path is incorrectly configured (/var/www/.cache/puppeteer)"**, Puppeteer no encuentra el navegador. La solución recomendada es usar **Chromium del sistema**:

```bash
# Instalar Chromium
sudo apt update
sudo apt install -y chromium

# Ver la ruta del ejecutable (suele ser /usr/bin/chromium)
which chromium
```

En el `.env` del proyecto añade (ajusta la ruta si `which chromium` devuelve otra):

```env
BROWSERSHOT_CHROME_PATH=/usr/bin/chromium
```

En algunos sistemas el paquete se llama `chromium-browser`; la ruta puede ser `/usr/bin/chromium-browser`. Después de guardar el `.env`, reinicia el worker de cola (`sudo supervisorctl restart laravel-queue:*`) para que las capturas vuelvan a enviarse.

Comprueba con:

```bash
php artisan browsershot:verificar
```

## 1. Dependencias de sistema (Ubuntu/Debian)

Browsershot fallará si faltan las librerías de renderizado. El error típico es **`libatk-1.0.so.0: cannot open shared object file`** (Code: 127). Ejecuta en tu VPS:

```bash
sudo apt-get update
sudo apt-get install -y \
  libgbm-dev \
  libnss3 \
  libatk1.0-0 \
  libatk-bridge2.0-0 \
  libgtk-3-0 \
  libasound2 \
  libx11-xcb1 \
  libxcomposite1 \
  libxdamage1 \
  libxrandr2 \
  libxshmfence1 \
  libgbm1
```

**Ubuntu 24.04 (Noble):** si `libasound2` no existe, usa `libasound2t64` y las variantes `t64` que te sugiera apt:

```bash
sudo apt-get install -y \
  libgbm-dev \
  libnss3 \
  libatk1.0-0t64 \
  libatk-bridge2.0-0t64 \
  libgtk-3-0t64 \
  libasound2t64 \
  libx11-xcb1 \
  libxcomposite1 \
  libxdamage1 \
  libxrandr2 \
  libxshmfence1 \
  libgbm1
```

(No uses `libgbm1fence1`: son dos paquetes, `libgbm1` y `libxshmfence1`.)

**Importante:** `libatk1.0-0` (o `libatk1.0-0t64`) es la que suele faltar y provoca *"Failed to launch the browser process"*. Sin ella verás "Captura no disponible" en Telegram.

## 2. Node.js y Puppeteer

```bash
# Node (si no lo tienes)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Puppeteer (en el proyecto o global)
cd /ruta/a/mayoreo-cloud
npm install puppeteer
# o global:
sudo npm install -g puppeteer
```

## 3. Permisos de Chromium

El código ya usa `--no-sandbox` y `--disable-setuid-sandbox` en `NotificadorTelegram::capturarPantallaProducto()`. Si aun así ves errores de permisos:

```bash
# Si Puppeteer está en node_modules del proyecto
sudo chown -R $USER:$USER node_modules/puppeteer
chmod -R o+rx node_modules/puppeteer/.local-chromium 2>/dev/null || true
```

## 4. Script de instalación rápida

En la raíz del proyecto puedes ejecutar:

```bash
bash scripts/instalar-dependencias-browsershot.sh
```

Instala todas las dependencias necesarias (detecta Ubuntu 24.04 y usa los paquetes correctos).

## 5. Probar que Browsershot funciona

```bash
php artisan browsershot:verificar
```

Si todo está bien, verás: `✓ Browsershot funciona. Las capturas de bajada histórica deberían enviarse correctamente.`  
Si falla por librerías, el comando te indicará que ejecutes el script de la sección 4.

## 6. Probar capturas con productos reales

```bash
# Probar con productos concretos (sincróno)
php artisan ofertas:procesar-bajadas --productos=3819,3809 --sync
```

Revisa `storage/logs/laravel.log` si la captura falla (timeout, CouldNotTakeBrowsershot).

## 7. Calimax (VTEX)

Para Calimax el código aplica automáticamente:

- **Timeout mínimo 45 s** (la tienda VTEX puede cargar lento).
- **User-Agent de Chrome real** para reducir bloqueos a navegadores headless.

En los logs verás `Intentando captura Browsershot para Calimax` y, si sale bien, `captura Browsershot Calimax OK`. Si sigue llegando solo texto, confirma en el servidor que Chromium arranca (`php artisan browsershot:verificar`) y que las dependencias están instaladas (sección 1).
