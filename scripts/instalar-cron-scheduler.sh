#!/bin/bash
# Instala el cron del scheduler de Laravel para que rastreo:todas y el resto de tareas
# se ejecuten solas (cada hora el rastreo, cada 5 min bajadas, etc.).
# Ejecutar como el usuario que debe correr el scheduler (ej. mayoreo o www-data).

set -e
PROYECTO="${1:-/home/mayoreo/htdocs/mayoreo-cloud}"
CRON_LINE="* * * * * cd ${PROYECTO} && php artisan schedule:run >> /dev/null 2>&1"

if ! crontab -l 2>/dev/null | grep -F "schedule:run" | grep -F "$PROYECTO" >/dev/null; then
  (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
  echo "Cron del scheduler instalado. El rastreo y las tareas programadas se ejecutarán solas."
else
  echo "El cron del scheduler ya estaba instalado."
fi
echo "Comprueba con: crontab -l"
