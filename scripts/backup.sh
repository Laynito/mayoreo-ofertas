#!/bin/bash
# Copia de seguridad Mayoreo Cloud: código + base de datos.
# Uso: bash scripts/backup.sh
# Cron: ejecutar diariamente (ej. 0 2 * * * = 02:00 cada día).

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_BASE="${BACKUP_BASE:-$(dirname "$PROJECT_ROOT")/backups/mayoreo-backup}"
FECHA=$(date +%Y-%m-%d_%H-%M)
NOMBRE="mayoreo-cloud"
BACKUP_DIR="$BACKUP_BASE/${NOMBRE}_${FECHA}"
ENV_FILE="$PROJECT_ROOT/.env"

# Retener solo los últimos N backups (carpetas); 0 = no borrar nada
RETENER=7

mkdir -p "$BACKUP_DIR"
cd "$PROJECT_ROOT"

echo "[$(date -Iseconds)] Iniciando backup en $BACKUP_DIR"

# --- Base de datos ---
if [ -f "$ENV_FILE" ]; then
  DB_CONNECTION=$(grep -E "^DB_CONNECTION=" "$ENV_FILE" | cut -d= -f2 | tr -d '"' | tr -d "'" || true)
else
  DB_CONNECTION="sqlite"
fi

if [ "$DB_CONNECTION" = "sqlite" ]; then
  DB_PATH="${DB_DATABASE:-$PROJECT_ROOT/database/database.sqlite}"
  if [ -f "$ENV_FILE" ]; then
    DB_DATABASE_ENV=$(grep -E "^DB_DATABASE=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'" || true)
    [ -n "$DB_DATABASE_ENV" ] && DB_PATH="$DB_DATABASE_ENV"
  fi
  if [ -f "$DB_PATH" ]; then
    cp "$DB_PATH" "$BACKUP_DIR/database.sqlite"
    echo "  BD SQLite copiada."
  else
    echo "  AVISO: No se encontró $DB_PATH"
  fi
elif [ "$DB_CONNECTION" = "mysql" ] || [ "$DB_CONNECTION" = "mariadb" ]; then
  DB_HOST=$(grep -E "^DB_HOST=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'" || echo "127.0.0.1")
  DB_PORT=$(grep -E "^DB_PORT=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'" || echo "3306")
  DB_USERNAME=$(grep -E "^DB_USERNAME=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_PASSWORD=$(grep -E "^DB_PASSWORD=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_DATABASE=$(grep -E "^DB_DATABASE=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
  DUMP="$BACKUP_DIR/database.sql"
  mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$DUMP"
  echo "  BD MySQL volcada en database.sql"
else
  echo "  AVISO: DB_CONNECTION=$DB_CONNECTION no manejado; omitiendo BD."
fi

# --- Código (excluir node_modules, .git, logs) ---
tar --exclude='node_modules' --exclude='.git' --exclude='storage/logs/*.log' -czf "$BACKUP_DIR/${NOMBRE}_codigo.tar.gz" -C "$(dirname "$PROJECT_ROOT")" "$(basename "$PROJECT_ROOT")"
echo "  Código empaquetado."

# --- README restauración ---
cat > "$BACKUP_DIR/README_RESTAURAR.txt" << EOF
=== COPIA DE SEGURIDAD MAYOREO-CLOUD ===
Fecha: $FECHA

CONTENIDO:
- database.sqlite o database.sql ..... Base de datos
- ${NOMBRE}_codigo.tar.gz ............. Código del proyecto

RESTAURAR CÓDIGO:
  cd /ruta/destino
  tar -xzvf ${NOMBRE}_codigo.tar.gz
  cd $NOMBRE
  cp /ruta/a/este/backup/database.sqlite database/database.sqlite   # o importar database.sql si MySQL
  composer install
  npm install && npm run build   # si aplica
  php artisan config:clear && php artisan cache:clear

.env no está incluido; configurar manualmente.
EOF

# --- Archivo único (opcional) ---
cd "$BACKUP_BASE"
tar -czvf "${NOMBRE}_backup_completo_${FECHA}.tar.gz" "$(basename "$BACKUP_DIR")" > /dev/null
echo "  Archivo único: ${NOMBRE}_backup_completo_${FECHA}.tar.gz"

# --- Retención: borrar backups antiguos ---
if [ "$RETENER" -gt 0 ]; then
  ls -dt "$BACKUP_BASE/${NOMBRE}"_* 2>/dev/null | tail -n +$((RETENER + 1)) | while read -r dir; do
    [ -d "$dir" ] && rm -rf "$dir" && echo "  Eliminado backup antiguo: $(basename "$dir")"
  done
  ls -t "$BACKUP_BASE/${NOMBRE}_backup_completo_"*.tar.gz 2>/dev/null | tail -n +$((RETENER + 1)) | while read -r f; do
    rm -f "$f" && echo "  Eliminado archivo antiguo: $(basename "$f")"
  done
fi

echo "[$(date -Iseconds)] Backup terminado: $BACKUP_DIR"
