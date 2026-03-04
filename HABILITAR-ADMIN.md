# Habilitar mayoreo.cloud (quitar 404 en /admin)

El **404 en https://mayoreo.cloud/admin** lo devuelve **nginx** porque el dominio no está apuntando al proyecto Laravel. Hay que usar el vhost que envía las peticiones a `index.php`.

## En el servidor (SSH con acceso sudo)

Ejecuta estos comandos **en el servidor donde está alojado mayoreo.cloud**:

```bash
# 1) Reemplazar el vhost actual por el de Laravel
sudo cp /home/mayoreo/htdocs/mayoreo-cloud/docs/nginx-mayoreo-cloud.conf /etc/nginx/sites-available/mayoreo.cloud.conf

# 2) Activar el sitio (enlace en sites-enabled)
sudo ln -sf /etc/nginx/sites-available/mayoreo.cloud.conf /etc/nginx/sites-enabled/

# 3) Comprobar PHP-FPM (si usas otra versión, edita el .conf y cambia php8.4-fpm.sock)
ls /var/run/php/

# 4) Probar configuración y recargar nginx
sudo nginx -t && sudo systemctl reload nginx
```

Si tu PHP no es 8.4, edita el archivo antes de recargar:

```bash
sudo sed -i 's/php8.4-fpm.sock/php8.2-fpm.sock/' /etc/nginx/sites-available/mayoreo.cloud.conf
# (cambia 8.2 por tu versión: 8.1, 8.3, etc.)
```

Después de esto, **https://mayoreo.cloud** y **https://mayoreo.cloud/admin** deben responder con la aplicación Laravel.

## WebSocket localhost:8081

Ese mensaje aparece si el navegador carga un script de “live reload” (por ejemplo una extensión o una build antigua). En el proyecto ya está `refresh: false` en Vite. Si sigue saliendo:

- Borra la caché del navegador para mayoreo.cloud.
- En el servidor, asegúrate de no tener `public/hot` (no debe existir en producción).

## Favicon 404

Cuando Laravel ya responda, el favicon se sirve desde `public/favicon.ico`. Si quieres evitar el 404 antes, puedes añadir un `favicon.ico` en `public/` del proyecto.
