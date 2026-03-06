# Unificar workers de Supervisor

El proyecto debe usar **un solo** worker: **mayoreo-worker**, que procesa las colas `high` (Amazon, Mercado Libre) y `default` (resto).

- **mayoreo-worker** (`deploy/supervisor/mayoreo-cloud.conf`): `queue:work --queue=high,default` → **úsalo**.
- **laravel-queue** (`deploy/supervisor-laravel-queue.conf`): mismo comando (high,default); es redundante si también tienes mayoreo-worker.

## Pasos para unificar en el servidor

1. Parar y quitar el worker antiguo:
   ```bash
   sudo supervisorctl stop laravel-queue:*
   sudo rm /etc/supervisor/conf.d/laravel-queue.conf
   # (o el nombre del archivo que hayas copiado para laravel-queue)
   ```

2. Dejar solo mayoreo-cloud:
   ```bash
   sudo cp /home/mayoreo/htdocs/mayoreo-cloud/deploy/supervisor/mayoreo-cloud.conf /etc/supervisor/conf.d/mayoreo-cloud.conf
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start mayoreo-worker:*
   ```

3. Comprobar:
   ```bash
   sudo supervisorctl status
   ```
   Debe aparecer solo: `mayoreo-worker:mayoreo-worker_00   RUNNING   ...`
