# Diagnóstico: supervisor y envío de ofertas

## Resumen

- **Supervisor/worker**: está corriendo y procesando jobs. No hay jobs fallidos en la cola.
- **Ofertas sí se están enviando** cuando hay productos que califican: en los logs hay entradas recientes de "oferta enviada a canal Free/Premium" (Coppel y Calimax el 2026-03-05).
- Lo que reduce la cantidad de ofertas es que **varias tiendas están bloqueadas o sin datos**, no un fallo del supervisor.

---

## 1. Cola y worker

- `php artisan queue:failed` → **No failed jobs**.
- `worker.log`: `EnviarOfertaTelegramJob` y `ProcesarBajadaDePrecioJob` aparecen como RUNNING y DONE con normalidad.

**Conclusión**: El supervisor está levantando el worker y los jobs se ejecutan. No hay error en la cola.

---

## 2. Motores bloqueados o sin datos (laravel.log 2026-03-05)

| Tienda         | Estado |
|----------------|--------|
| **Walmart**    | 403 "Verifica tu identidad" (bloqueo). Motor marcado como fallo. |
| **Sams Club**  | API 403 "You are not Authorized"; HTML 404 en rebajas. |
| **Amazon**      | Extracción fallida (selectores / estructura). |
| **Office Depot** | 12 productos extraídos pero **ninguno con precio válido** (todos filtrados). |
| **Costco**     | No se extrajeron productos (cambio de estructura). |
| **AliExpress**  | No se extrajeron productos (bloqueo o estructura). |
| **MercadoLibre** | 403. |
| **Coppel**     | Sí envía ofertas (varias "oferta enviada" 18:01–18:02). |
| **Calimax**    | Sí ha enviado ofertas en otros horarios (01:51, 04:41, etc.). |
| **Elektra**    | 1 producto vía API en el rastreo reciente. |

Por tanto, en la última ejecución horaria la mayoría de ofertas que pueden salir vienen de **Coppel** (y en otros momentos de Calimax). Si “no llegan ofertas” en un rato concreto, puede ser que ese ciclo solo haya tenido Coppel con pocos productos que califiquen.

---

## 3. Otros avisos en logs (no impiden que se manden ofertas)

- **Imágenes Coppel**: timeout al descargar (cURL 28). Se reenvía la oferta **solo texto**; el mensaje sí llega a Telegram.
- **Browsershot (captura bajada histórica)**: en el servidor falla Chrome por librería faltante:
  - `libatk-1.0.so.0: cannot open shared object file`
  - Solución: instalar dependencias (ver `docs/BROWSERSHOT-LINUX.md`). Mientras tanto, la notificación de bajada histórica se envía sin captura (“captura no disponible”).
- **SQLite readonly** (sesiones): errores antiguos (2026-03-03) al actualizar sesiones; no afectan al worker que usa la misma BD para ofertas/productos si la BD es escribible para el usuario que corre el worker.
- **Filament**: error en `EstadisticasTiendasWidget` (redeclaración de `$heading`); no afecta al rastreo ni a Telegram.

---

## 4. Qué revisar si “no llegan ofertas”

1. **Horario**: `rastreo:todas` está programado **hourly** en `routes/console.php`. Comprobar que el cron de Laravel esté activo:
   ```bash
   crontab -l
   ```
   Debe haber una línea como:
   ```text
   * * * * * cd /home/mayoreo/htdocs/mayoreo-cloud && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Supervisor**:
   ```bash
   sudo supervisorctl status
   ```
   El proceso del worker (por ejemplo `laravel-worker:laravel-worker_00`) debe estar `RUNNING`.

3. **Última ejecución del rastreo**:
   ```bash
   grep -E "Rastreando ofertas|Obteniendo ofertas|oferta enviada|No se encontraron productos" storage/logs/laravel.log | tail -80
   ```

4. **Estado de motores**: en Filament, **Rastreo → Estado de motores**, comprobar si Walmart (u otros) están marcados como bloqueados y si quieres reactivarlos más adelante.

---

## 5. Conclusión

No hay un error que impida que el supervisor “mande ofertas”. Las ofertas se envían cuando el rastreo encuentra productos que califican; en la última revisión eso ocurre sobre todo con **Coppel** (y en otros horarios con **Calimax**). La sensación de “no mandar ofertas” se debe sobre todo a:

- Varias tiendas bloqueadas o sin datos (Walmart, Sams, Amazon, Office Depot, Costco, AliExpress, MercadoLibre).
- Posiblemente pocos productos con descuento que califiquen en ese ciclo horario.

Recomendación: asegurar cron + supervisor y revisar Estado de motores; opcionalmente ajustar o reactivar motores bloqueados cuando quieras volver a probar esas tiendas.
