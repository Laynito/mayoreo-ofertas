# Conexión a Admitad

Guía para conectar **mayoreo-cloud** con [Admitad](https://www.admitad.com) como red de afiliados (publisher).

## 1. Cuenta y credenciales

1. Regístrate o inicia sesión en **Admitad** (como webmaster/publisher).
2. Entra en **Tu cuenta** → **Configuración** → **Credenciales** y haz clic en **Mostrar credenciales**.
3. Copia:
   - **ID de la aplicación** (`client_id`)
   - **Clave secreta** (`client_secret`)

Documentación oficial: [Admitad Developers – Client authorization](https://developers.admitad.com/knowledge-base/article/client-authorization_2).

## 2. Configuración en el proyecto

### Variables de entorno

Añade en tu `.env`:

```env
# Admitad (red de afiliados)
ADMITAD_CLIENT_ID=tu_client_id
ADMITAD_CLIENT_SECRET=tu_client_secret
# Incluye private_data para GET /me/ (y admitad:test). Si cambias el scope, limpia caché: AdmitadConnector::clearTokenCache()
# ADMITAD_SCOPE="advcampaigns banners websites private_data"
```

La configuración se lee en `config/services.php` bajo la clave `admitad`.

### Verificación del sitio (meta tag)

Si Admitad te pide **verificar tu sitio** con una etiqueta `<meta>`:

1. En **Filament** → **Marketplace**, edita el marketplace que use Admitad (o crea uno con slug adecuado).
2. En el campo **Código de verificación meta**, pega la etiqueta que te dio Admitad (ej. `<meta name="admitad-verification" content="...">`).
3. Asegúrate de que en tu layout o vista se inyecte `verification_code` en el `<head>` cuando ese marketplace esté activo.

Así el dominio queda verificado en Admitad sin tocar archivos estáticos.

## 3. Uso de la API desde Laravel

El proyecto incluye un conector Saloon para la API de Admitad:

- **Conector:** `App\Http\Integrations\Admitad\AdmitadConnector`
- **Token:** se obtiene con OAuth 2.0 `client_credentials` y se guarda en caché (TTL según `expires_in` de Admitad).

### Ejemplo: obtener token y hacer una petición

```php
use App\Http\Integrations\Admitad\AdmitadConnector;

$connector = new AdmitadConnector();
// La primera petición que no sea GetTokenRequest obtendrá el token automáticamente
// y lo usará como Bearer en las siguientes.
```

Para añadir nuevos métodos (listar campañas, banners, etc.):

1. Crea un `Request` en `App\Http\Integrations\Admitad\Requests\` que extienda `Saloon\Http\Request`.
2. Envía el request con `$connector->send(new TuRequest(...))`.

La base URL de la API es `https://api.admitad.com`. Endpoints y parámetros: [Publisher API Methods](https://developers.admitad.com/knowledge-base/articles/publisher-api-methods).

### Limpiar caché del token

Si cambias de credenciales o quieres forzar un nuevo token:

```php
use App\Http\Integrations\Admitad\AdmitadConnector;

AdmitadConnector::clearTokenCache();
```

## 4. Resumen de archivos

| Qué | Dónde |
|-----|--------|
| Configuración | `config/services.php` → `admitad` |
| Conector Saloon | `app/Http/Integrations/Admitad/AdmitadConnector.php` |
| Request de token | `app/Http/Integrations/Admitad/Requests/GetTokenRequest.php` |
| Meta verificación | Campo `verification_code` del modelo `Marketplace` (Filament) |

## 5. Probar la conexión

Desde la raíz del proyecto:

```bash
php artisan admitad:test
```

Obtiene el token y llama a `GET /me/` (datos del publisher). Si ves tu usuario y tabla de datos, la conexión está correcta. Si obtienes 403 en `/me/`, añade el scope `private_data` en `ADMITAD_SCOPE` (entre comillas) y limpia la caché del token (en código: `AdmitadConnector::clearTokenCache()` o espera a que expire el token).

## 6. Listar programas de afiliados

- **Comando:** `php artisan admitad:programs` (opciones: `--limit=20`, `--offset=0`, `--language=es`, `--website=ID`)
- **Filament:** Admin → **Admitad** → **Programas Admitad** (tabla con ID, nombre, URL, estado, conectado).

## 7. Cupones

- **Comando:** `php artisan admitad:coupons` (opciones: `--limit=20`, `--region=MX`, `--campaign=ID`, `--search=...`)
- **Filament:** Admin → **Admitad** → **Cupones Admitad** (tabla con nombre, programa, descuento, enlace).
- Requiere scope `coupons` en `ADMITAD_SCOPE` (ya incluido por defecto).

## 8. Generar enlaces de afiliado (Deeplink)

- **Filament:** Admin → **Admitad** → **Generar enlace**: indica *website_id* (espacio publicitario), *campaign_id* (programa) y las URLs (una por línea). Genera enlaces de afiliado.
- **Desde código:** `app(AdmitadService::class)->generateDeeplinks(websiteId, campaignId, ['https://...'], subid)`.
- **Acortar enlace:** `app(AdmitadService::class)->shortenLink('https://ad.admitad.com/...')` (el enlace debe ser de dominio Admitad).
- Requiere scopes `deeplink_generator` y `short_link` (incluidos por defecto). Para obtener tu *website_id*: en Admitad → Espacios publicitarios, o llamar a `GET /websites/v2/` (método `AdmitadService::getWebsites()`).

## 9. Verificación del sitio

- En **Filament** → **Marketplace**, edita un marketplace y pega en **Código de verificación meta** la etiqueta que te da Admitad.
- El layout público `layouts.front` (Precios Bajos) inyecta automáticamente ese código en el `<head>` si el marketplace está activo y tiene `verification_code` guardado. No hace falta tocar vistas manualmente.

## 10. Otros métodos (referencia)

| Objetivo | API / método | Descripción |
|----------|----------------|-------------|
| Banners | [Banners](https://developers.admitad.com/knowledge-base/article/banners_1) | Banners por campaña |
| Espacios publicitarios | [Publisher ad spaces](https://developers.admitad.com/knowledge-base/article/publisher-ad-spaces_1) | Listar tus sitios: `GET /websites/v2/` → `AdmitadService::getWebsites()` |

Si algún método pide un scope distinto, añádelo en `ADMITAD_SCOPE` en `.env` y limpia la caché del token (`AdmitadConnector::clearTokenCache()`).

## 11. Enlaces útiles

- [Admitad Developers](https://developers.admitad.com/)
- [Client authorization (OAuth 2.0)](https://developers.admitad.com/knowledge-base/article/client-authorization_2)
- [Publisher API Methods](https://developers.admitad.com/knowledge-base/articles/publisher-api-methods)
