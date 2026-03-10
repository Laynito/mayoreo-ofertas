# Ofertas en Facebook / Meta

Tienes tres formas de llevar las ofertas a Facebook e Instagram.

---

## 1. Vía Pro: Catálogo en Meta Commerce Manager

**Ventaja:** Catálogo profesional que se actualiza solo; Meta lee el feed cada vez que configures (ej. cada hora).

### Pasos

1. **URL del feed (ya implementada):**
   ```
   https://tu-dominio.com/api/facebook-feed
   ```
   Sustituye `tu-dominio.com` por tu dominio real (ej. `mayoreo.cloud`). El feed devuelve CSV con: id, title, description, availability, condition, price, link, image_link, brand.

2. **En Meta:**
   - Entra a [Commerce Manager](https://business.facebook.com/commerce).
   - Catálogos → Crear catálogo → Productos.
   - Agregar fuente de datos → **Lista de datos programada**.
   - Pega la URL del feed y programa la frecuencia (ej. cada 1 hora).

3. **Requisitos:** Las imágenes deben ser accesibles por Meta. Si usas imágenes locales (`/storage/imagenes/...`), `APP_URL` en `.env` debe ser tu dominio público (ej. `https://mayoreo.cloud`) para que el feed genere URLs que Meta pueda descargar.

---

## 2. Vía Social: Publicar en la Fan Page (Python)

**Ventaja:** Posts automáticos con imagen, precio y link de afiliado en tu Fan Page.

### Configuración

1. **Token de página (Page Access Token de larga duración):**
   - [Meta for Developers](https://developers.facebook.com/) → Tu app (o crea una).
   - Herramientas → Token de acceso → Generar token con permisos `pages_manage_posts`, `pages_read_engagement`.
   - Para que sea de larga duración: usa “Obtener token de acceso de larga duración” para la página.

2. **Variables en `.env`:**
   ```env
   FB_PAGE_ACCESS_TOKEN=tu_page_access_token
   FB_PAGE_ID=123456789012345
   APP_URL=https://mayoreo.cloud
   ```
   Si no pones `FB_PAGE_ID`, el script intenta obtenerlo con el token (solo si tienes una página).

3. **Ejecución:**
   ```bash
   cd /home/mayoreo/htdocs/mayoreo-cloud
   python python/facebook_publisher.py
   ```
   Por defecto publica los **5** productos con mayor % de descuento (cambiable con `FB_TOP_N=10`).

4. **Automatizar (cron):** Por ejemplo, una vez al día a las 10:00:
   ```bash
   0 10 * * * cd /home/mayoreo/htdocs/mayoreo-cloud && python python/facebook_publisher.py
   ```

### Permisos válidos (no uses manage_pages)

Meta ha dejado de usar el permiso **manage_pages**; si lo pides, verás "Invalid Scopes: manage_pages". Usa **solo** estos:

- **pages_show_list** – para listar tus páginas
- **pages_read_engagement** – para leer datos de la página
- **pages_manage_posts** – para crear y publicar posts en la página

En el Explorador de la API Graph, al generar el token pide solo esos tres. No marques `manage_pages` ni `publish_pages`.

### Token que no caduca (hacer una sola vez)

El token que sacas del Explorador caduca en poco tiempo. Para obtener un **token de página que no caduca** (o dura 60+ días):

1. En [developers.facebook.com](https://developers.facebook.com) → Tu app → **Configuración → Básica**: copia **ID de la aplicación** y **Clave secreta de la aplicación**.
2. En `.env` añade (sin comillas en los valores):
   ```env
   FB_APP_ID=tu_id_de_app
   FB_APP_SECRET=tu_clave_secreta
   ```
3. En el [Explorador de la API Graph](https://developers.facebook.com/tools/explorer/) genera un token de **usuario** con permisos `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`.
4. Ejecuta el script que canjea ese token corto por el token de página permanente:
   ```bash
   cd /home/mayoreo/htdocs/mayoreo-cloud
   python/venv/bin/python python/facebook_get_long_lived_token.py
   ```
   Te pedirá que pegues el token del paso 3 (o puedes ponerlo en .env como `FB_TOKEN_CORTO=...`).
5. El script imprime el **token de la PÁGINA**. Cópialo y pégalo en `.env` como `FB_PAGE_ACCESS_TOKEN="..."`. Ese token no caduca y no tendrás que renovarlo.

### Error 403: "This app is not allowed to publish to other users' timelines"

Ese error significa que estás usando un **token de usuario** en lugar del **token de la página**. Para publicar en la Fan Page hace falta el **Page Access Token**:

1. Entra a [Explorador de la API Graph](https://developers.facebook.com/tools/explorer/), elige tu app y genera un token con permisos: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` (sin `manage_pages`).
2. En el explorador, haz una petición **GET** a: `me/accounts` (o abre en el navegador la URL que te da el explorador para esa petición).
3. En la respuesta JSON verás una lista `data` con tus páginas. Cada elemento tiene `id` y `access_token`. El **access_token** de esa página es el que debes usar (Panel → Marketplace → Facebook, o .env como `FB_PAGE_ACCESS_TOKEN`). El **id** es el Page ID (`FB_PAGE_ID`).
4. Sustituye en el panel o en .env el token actual por ese `access_token` de la página y vuelve a ejecutar el script.  
   **Mejor aún:** usa el script `facebook_get_long_lived_token.py` (ver arriba) para obtener un token que no caduca.

---

## 3. Vía Viral: Make (Integromat) + Webhook

**Ventaja:** Sin programar más código; disparas publicaciones desde Make cuando haya una “súper oferta”.

### Idea

- En Laravel: al guardar/actualizar un producto con descuento > 50%, llamar a un Webhook de Make (HTTP POST con producto).
- En Make: el webhook recibe el producto y ejecuta una acción “Publicar en Facebook” (o Instagram / Telegram).

Si quieres esta vía, se puede añadir una ruta o evento en Laravel que dispare el webhook cuando el scraper detecte una oferta con X% de descuento.
