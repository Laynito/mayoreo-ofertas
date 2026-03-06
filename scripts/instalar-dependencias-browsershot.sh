#!/bin/bash
# Instala dependencias de sistema para Browsershot/Puppeteer (Chromium) en Linux.
# Ejecutar en el VPS: bash scripts/instalar-dependencias-browsershot.sh
# Requiere: sudo

set -e

echo "Instalando dependencias para Browsershot (Chromium) en Linux..."

# Ubuntu 24.04 (Noble) usa sufijo t64 en algunos paquetes
if grep -q "Noble" /etc/os-release 2>/dev/null || grep -q "24.04" /etc/os-release 2>/dev/null; then
    echo "Detectado Ubuntu 24.04 (Noble). Usando paquetes t64."
    sudo apt-get update
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
else
    echo "Instalando paquetes estándar (Debian/Ubuntu < 24)."
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
fi

echo ""
echo "Dependencias instaladas. Prueba las capturas con:"
echo "  php artisan browsershot:verificar"
echo ""
