# Browsershot (Puppeteer) en Linux / VPS

Para que las capturas de pantalla de bajadas históricas funcionen en un servidor Linux (Ubuntu/Debian), instala las dependencias de sistema y asegura permisos de Chromium.

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

## 4. Probar capturas

```bash
# Probar con productos concretos (sincróno)
php artisan ofertas:procesar-bajadas --productos=3819,3809 --sync
```

Revisa `storage/logs/laravel.log` si la captura falla (timeout, CouldNotTakeBrowsershot).
