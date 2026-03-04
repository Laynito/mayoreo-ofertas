#!/bin/bash
# Ejecutar EN EL SERVIDOR con sudo para que mayoreo.cloud sirva la app Laravel (quitar 404).
# Uso: sudo bash /home/mayoreo/htdocs/mayoreo-cloud/scripts/habilitar-nginx-mayoreo-cloud.sh

set -e
CONF_SRC="/home/mayoreo/htdocs/mayoreo-cloud/docs/nginx-mayoreo-cloud.conf"
CONF_DEST="/etc/nginx/sites-available/mayoreo.cloud.conf"

if [ ! -f "$CONF_SRC" ]; then
  echo "No se encuentra $CONF_SRC"
  exit 1
fi

cp "$CONF_SRC" "$CONF_DEST"
ln -sf "$CONF_DEST" /etc/nginx/sites-enabled/mayoreo.cloud.conf
echo "Configuración instalada. Comprobando nginx..."
nginx -t
systemctl reload nginx
echo "Listo. Prueba: https://mayoreo.cloud y https://mayoreo.cloud/admin"
