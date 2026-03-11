# Solución 403 en /storage/ (imágenes para Facebook)

Si `curl -I "https://mayoreo.cloud/storage/imagenes/..."` devuelve **403**, nginx está bloqueando la ruta.

## Causa habitual: `root` apunta a la raíz del proyecto en lugar de a `public`

En la configuración de nginx del sitio (ej. `/etc/nginx/sites-available/mayoreo.cloud` o similar), la directiva **`root`** debe apuntar a la carpeta **`public`** de Laravel:

```nginx
server {
    listen 443 ssl;
    server_name mayoreo.cloud;
    # ...
    root /home/mayoreo/htdocs/mayoreo-cloud/public;   # ← tiene que ser /public
    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;  # ajusta versión PHP
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

- **Mal:** `root /home/mayoreo/htdocs/mayoreo-cloud;`  
  Entonces la petición `/storage/imagenes/coppel/xxx.jpg` se resuelve a la carpeta real `storage/` (fuera de `public`), y nginx suele devolver 403.

- **Bien:** `root /home/mayoreo/htdocs/mayoreo-cloud/public;`  
  Entonces `/storage/imagenes/...` se sirve desde `public/storage` (el enlace simbólico a `storage/app/public`).

## Pasos en el servidor

1. Ver la configuración activa:
   ```bash
   grep -r "root.*mayoreo" /etc/nginx/
   ```
   Asegúrate de que en el `server` que atiende `mayoreo.cloud` el `root` termine en **`/public`**.

2. Si hay que cambiarlo, edita el fichero del sitio:
   ```bash
   sudo nano /etc/nginx/sites-available/mayoreo.cloud
   ```
   Pon:
   ```nginx
   root /home/mayoreo/htdocs/mayoreo-cloud/public;
   ```

3. Comprobar y recargar nginx:
   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

4. Probar de nuevo:
   ```bash
   curl -I "https://mayoreo.cloud/storage/imagenes/coppel/63928688-1.jpg"
   ```
   Debe devolver **200** y `content-type: image/...`.
