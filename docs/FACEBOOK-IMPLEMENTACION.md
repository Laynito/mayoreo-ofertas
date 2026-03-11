# Plan de implementación: Facebook / Meta en mayoreo-cloud

## Ya implementado

### 1. Publicar ofertas en la Fan Page (Python + cron)
- Script `python/facebook_publisher.py`: publica las ofertas con mayor descuento (50%+) que no se hayan publicado hoy.
- Comando Laravel: `php artisan facebook:publish`.
- Scheduler: 3 veces al día (08:00, 13:00, 18:00).
- Config: `FB_PAGE_ID`, `FB_PAGE_ACCESS_TOKEN`, `FB_TOP_N`, `APP_URL` en .env.

### 2. Feed de catálogo para Commerce Manager
- Ruta: `GET /api/facebook-feed` (CSV para Meta).
- Configurar en Commerce Manager como “Lista de datos programada”.

### 3. Fase 1 – Panel y métricas (nuevo)
- **FacebookService** (`app/Services/FacebookService.php`): cliente Graph API para la página.
  - `getPageInfo()` – nombre e ID de la página.
  - `getInsights($metrics, $period, $since, $until)` – métricas (impresiones, alcance, fans). Requiere 100+ me gusta y permiso `read_insights`.
  - `getPublishedPosts($limit)` – publicaciones recientes.
  - `deletePost($postId)` – eliminar una publicación.
- **Comando** `php artisan facebook:insights`: muestra insights por consola (opción `--days`, `--metric`).
- **Página Filament “Facebook”** (grupo Marketing): información de la página, resumen de insights (7 días), lista de publicaciones recientes con enlace y botón “Eliminar”, y botón “Publicar oferta ahora” que ejecuta `facebook:publish`.

---

## Cómo seguir ampliando

### A. Programar publicaciones (elegir día/hora)
- **Idea:** Guardar en BD “publicar producto X el día D a la hora H”. Un cron cada 5–10 min revisa y, si es la hora, llama a la API (o al script Python pasando producto/id).
- **Modelo:** `facebook_scheduled_posts` (producto_id, scheduled_at, status, post_id opcional).
- **Panel:** Formulario en Filament para elegir producto + fecha/hora; listado de programadas con opción de cancelar.
- **Backend:** Comando `facebook:publish-scheduled` o extender el Python para aceptar “solo este producto” y ejecutarlo desde un Job a la hora indicada.

### B. Webhook para comentarios / mensajes
- **Idea:** Meta envía POST a tu URL cuando hay comentario o mensaje nuevo; tu app responde (ej. con 200) y opcionalmente guarda el evento o responde (API de comentarios / Messenger).
- **Ruta:** `POST /api/facebook/webhook` (y `GET` para verificación con `hub.verify_token`, `hub.challenge`).
- **Config en Meta:** App → Webhooks → Suscribirse a “Page” (eventos `feed`, `messages`, etc.) y poner la URL.
- **Laravel:** Controlador que valide firma, decodifique el payload y encole un Job para procesar (guardar en BD, notificación en panel, o respuesta automática con la Graph API).

### C. Responder comentarios desde el panel
- **Idea:** En la página “Facebook” (o en un recurso “Comentarios”) listar comentarios de posts recientes y permitir responder como la página.
- **API:** `GET /{post-id}/comments`, `POST /{comment-id}/comments` (responder) con token de página y permiso `pages_manage_engagement`.
- **FacebookService:** `getPostComments($postId)`, `replyToComment($commentId, $message)`.
- **Panel:** Tabla de comentarios con campo de respuesta y botón “Enviar”.

### D. Histórico de insights en BD
- **Idea:** Guardar diariamente las métricas en una tabla para gráficas y tendencias.
- **Modelo:** `facebook_insights_daily` (page_id, date, metric_name, value).
- **Comando:** `facebook:insights` (o uno nuevo `facebook:sync-insights`) que llame a `getInsights`, persista en BD y se ejecute 1 vez al día por cron.
- **Panel:** Gráficas en la página Facebook (o widget) leyendo de esa tabla.

### E. Publicaciones con solo texto o enlace (sin imagen)
- **Idea:** Para productos sin imagen válida, publicar en el feed con `message` + `link` en lugar de `/photos`.
- **Cambio:** En `facebook_publisher.py`, si la imagen falla o no hay imagen, hacer POST a `/{page_id}/feed` con `message` y `link` (url_afiliado).

---

## Permisos útiles en la app de Meta

| Permiso | Uso |
|--------|-----|
| `pages_show_list` | Listar páginas del usuario. |
| `pages_read_engagement` | Leer interacción y publicaciones; necesario para insights y comentarios. |
| `pages_manage_posts` | Crear/editar/eliminar publicaciones (ya lo usas). |
| `read_insights` | Leer métricas de la página (insights). |
| `pages_manage_engagement` | Responder comentarios y gestionar engagement. |

Para webhooks no se piden permisos extra en el sentido de “scopes”; la app debe estar suscrita a los eventos en la configuración de la app.

---

## Orden sugerido

1. **Ya hecho:** Servicio, insights en panel, listar/eliminar posts, comando `facebook:insights`.
2. **Siguiente:** Histórico de insights en BD + gráficas (comando diario + tabla).
3. **Después:** Programar publicaciones (modelo + cron + UI).
4. **Opcional:** Webhook + respuestas a comentarios desde el panel.
