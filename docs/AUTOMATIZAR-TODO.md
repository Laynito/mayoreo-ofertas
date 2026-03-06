# Dejar todo automático (rastreo + Telegram)

Para que el rastreo de ofertas y el envío a Telegram funcionen **solos**, hacen falta **dos cosas**: el **cron del scheduler** y el **worker de cola** (Supervisor).

---

## 1. Cron del scheduler (rastreo cada hora)

El scheduler de Laravel lanza:
- **Cada hora:** `rastreo:todas` (Mercado Libre, Amazon, Walmart, etc.)
- **Cada 5 minutos:** procesar bajadas de precio
- **Diario:** limpiar capturas y mensajes antiguos en Telegram

**Instalación (una sola vez):**

```bash
# Ajusta la ruta si tu proyecto está en otro sitio
chmod +x /home/mayoreo/htdocs/mayoreo-cloud/scripts/instalar-cron-scheduler.sh
/home/mayoreo/htdocs/mayoreo-cloud/scripts/instalar-cron-scheduler.sh
```

O a mano con `crontab -e`, añade esta línea (una entrada por minuto es suficiente):

```
* * * * * cd /home/mayoreo/htdocs/mayoreo-cloud && php artisan schedule:run >> /dev/null 2>&1
```

**Comprobar:** `crontab -l` debe mostrar esa línea.

---

## 2. Worker de cola con Supervisor (que lleguen los Telegram)

El rastreo **encola** los mensajes; para que **se envíen a Telegram** tiene que estar corriendo un worker que procese la cola.

**Instalación (una sola vez):**

```bash
# Instalar Supervisor si no lo tienes
sudo apt install -y supervisor

# Copiar la config del proyecto (ajusta la ruta si es distinta)
sudo cp /home/mayoreo/htdocs/mayoreo-cloud/deploy/supervisor-laravel-queue.conf /etc/supervisor/conf.d/laravel-queue.conf

# Ajustar ruta y usuario en el .conf si hace falta
sudo nano /etc/supervisor/conf.d/laravel-queue.conf

# Activar y arrancar
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-queue:*
```

**Comprobar:** `sudo supervisorctl status` → debe salir `laravel-queue:laravel-queue_00   RUNNING`.

---

## Resumen

| Qué                | Cómo se automatiza                          |
|--------------------|---------------------------------------------|
| Rastreo de tiendas | Cron ejecuta `schedule:run` cada minuto     |
| Envío a Telegram   | Supervisor mantiene `queue:work` corriendo  |

Con **1 + 2** configurados, no hace falta ejecutar nada a mano: el rastreo se lanza cada hora y los mensajes se envían a Telegram en cuanto el worker procesa la cola.

---

## Copiar y pegar (todo en uno)

En el servidor, como usuario con acceso al proyecto:

```bash
# 1) Cron del scheduler (rastreo cada hora)
(crontab -l 2>/dev/null; echo "* * * * * cd /home/mayoreo/htdocs/mayoreo-cloud && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# 2) Supervisor (worker para Telegram) — requiere sudo
sudo apt install -y supervisor
sudo cp /home/mayoreo/htdocs/mayoreo-cloud/deploy/supervisor-laravel-queue.conf /etc/supervisor/conf.d/laravel-queue.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start laravel-queue:*

# Comprobar
crontab -l
sudo supervisorctl status
```
