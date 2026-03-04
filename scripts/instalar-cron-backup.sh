#!/bin/bash
# Añade la tarea cron para backup diario (02:00).
# Uso: bash scripts/instalar-cron-backup.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_SCRIPT="$PROJECT_ROOT/scripts/backup.sh"
LOG_FILE="$PROJECT_ROOT/storage/logs/backup.log"
CRON_LINE="0 2 * * * /bin/bash $BACKUP_SCRIPT >> $LOG_FILE 2>&1"

if [ ! -x "$BACKUP_SCRIPT" ]; then
  chmod +x "$BACKUP_SCRIPT"
fi
mkdir -p "$(dirname "$LOG_FILE")"

if crontab -l 2>/dev/null | grep -F "$BACKUP_SCRIPT" >/dev/null; then
  echo "Ya existe una entrada de cron para el backup."
  exit 0
fi

(crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
echo "Cron instalado: backup diario a las 02:00. Log: $LOG_FILE"
