#!/bin/bash
# Corrige el worker de Supervisor para que procese las colas high y default (Mercado Libre, Amazon y resto).
# Ejecutar en el servidor: sudo bash /home/mayoreo/htdocs/mayoreo-cloud/scripts/arreglar-worker-supervisor.sh

set -e
CONF="/etc/supervisor/conf.d/mayoreo-cloud.conf"

if [ ! -f "$CONF" ]; then
  echo "No existe $CONF. ¿Usas otro nombre? Lista: ls /etc/supervisor/conf.d/"
  exit 1
fi

# Añadir database y --queue=high,default si no están
if grep -q 'queue:work database --queue=high,default' "$CONF"; then
  echo "El worker ya tiene database y high,default. No hay nada que cambiar."
else
  sed -i.bak 's|queue:work --sleep|queue:work database --queue=high,default --sleep|' "$CONF"
  echo "Config actualizada. Respaldo en ${CONF}.bak"
fi

echo "Recargando Supervisor y reiniciando mayoreo-worker..."
supervisorctl reread
supervisorctl update
supervisorctl restart mayoreo-worker:*

echo ""
echo "Listo. Comprueba: supervisorctl status"
grep '^command=' "$CONF"
