# Supervisor – Cola Laravel (mayoreo-cloud)

El worker procesa primero la cola `high` (Amazon y Mercado Libre) y luego `default` (resto de tiendas). Así los jobs de las tiendas principales salen antes que los de tiendas más pequeñas (Sears, etc.).

## 1. Instalar Supervisor

```bash
apt update && apt install supervisor -y
```

## 2. Instalar la configuración del worker

```bash
# Copiar el archivo al directorio de Supervisor (ruta del servidor)
sudo cp deploy/supervisor/mayoreo-cloud.conf /etc/supervisor/conf.d/mayoreo-cloud.conf

# O crearlo a mano:
sudo nano /etc/supervisor/conf.d/mayoreo-cloud.conf
# Pegar el contenido de deploy/supervisor/mayoreo-cloud.conf
```

Comprueba que la ruta del proyecto sea la correcta (`/home/mayoreo/htdocs/mayoreo-cloud`). Si usas otro usuario, cambia `user=root` por tu usuario.

## 3. Activar el worker

```bash
supervisorctl reread
supervisorctl update
supervisorctl start mayoreo-worker:*
```

## 4. Ver estado

```bash
supervisorctl status
```

Salida esperada:

```
mayoreo-worker:mayoreo-worker_00   RUNNING   pid 1234, uptime 0:00:05
```

## Comandos útiles

| Comando | Descripción |
|--------|-------------|
| `supervisorctl status` | Ver estado del worker |
| `supervisorctl stop mayoreo-worker:*` | Parar el worker |
| `supervisorctl start mayoreo-worker:*` | Arrancar el worker |
| `supervisorctl restart mayoreo-worker:*` | Reiniciar (tras cambiar código o .env) |
| `tail -f storage/logs/worker.log` | Ver log del worker en tiempo real |

## Tras desplegar código

Tras un `git pull` o cambio de código, reinicia el worker para que use el nuevo código:

```bash
supervisorctl restart mayoreo-worker:*
```
