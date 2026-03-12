# Crear una app en TikTok for Developers

Guía para registrar la app de **Cazador De Precios** en TikTok y obtener las credenciales (Client Key, Client Secret).

## 1. Requisitos previos

1. **Cuenta de desarrollador**  
   Regístrate en: [TikTok for Developers - Sign up](https://developers.tiktok.com/signup) con tu email.

2. **Organización (recomendado)**  
   Crea o únete a una [organización](https://developers.tiktok.com/doc/working-with-organizations). Puedes registrar la app bajo tu cuenta personal, pero para uso real es mejor usar una organización.

## 2. Conectar tu app

1. Entra en [Manage apps](https://developers.tiktok.com/apps) (icono de perfil → **Manage apps**).
2. Inicia sesión con tu cuenta de desarrollador de TikTok.
3. Pulsa **Connect an app**.
4. Cuando pida **Select the app owner**, elige tu organización (o tu cuenta si no usas organización) y confirma.

## 3. Configurar la app

### Credenciales

- **Client key** y **Client secret**: se generan al crear la app. Guárdalos; los usarás en el panel Admin → Marketplaces → TikTok (o en `.env`).

### Información básica

- **App name**: `Cazador De Precios` (o el nombre que uses en TikTok).
- **App icon**: imagen 1024×1024 px, JPEG/PNG, máx. 5 MB.
- **Category**: elige la que mejor encaje (ej. Compras, Utilidades).

### Description * (máx. 120 caracteres)

Texto que se muestra a los usuarios de TikTok. Puedes copiar y pegar uno de estos (o guardarlo en Admin → Marketplaces → TikTok → "Description (app, 120 caracteres)"):

**Opción en inglés (120 caracteres):**
```
A website that shows daily deals from Mercado Libre, Coppel and more. Find the best prices. Use the link in bio to shop.
```

**Opción en español (119 caracteres):**
```
Sitio de ofertas del día de Mercado Libre, Coppel y más. Encuentra los mejores precios. Usa el link en bio para comprar.
```

### Terms of Service URL *

Enlace obligatorio a los términos de uso de tu sitio:

```
https://mayoreo.cloud/terminos
```

(La ruta `/terminos` está publicada en este proyecto; puedes editarla en `resources/views/legal/terminos.blade.php`.)

### Privacy Policy URL *

Enlace obligatorio al aviso de privacidad:

```
https://mayoreo.cloud/aviso-de-privacidad
```

(La ruta `/aviso-de-privacidad` está en `resources/views/legal/aviso-privacidad.blade.php`.)

### Platforms *

- Marca **Web** (recomendado para link en bio).
- Marca **Desktop** si aplica.
- **Android** / **iOS** solo si más adelante tienes app nativa.
- **Website URL**: `https://mayoreo.cloud` (o tu dominio).
- Si usas **Login Kit** o **Share Kit**, añade **Redirect URI** (ej. `https://mayoreo.cloud/auth/tiktok/callback`).

### Productos (kits y APIs disponibles en TikTok Development)

En **Products** → **Add products** verás opciones como:

| Producto | Uso recomendado para Cazador De Precios |
|----------|----------------------------------------|
| **Login Kit** | Solo si quieres “Iniciar sesión con TikTok” en la web. |
| **Share Kit** | Recomendado: que usuarios compartan ofertas/links en TikTok (link en bio). |
| **Content Posting API** | Publicar vídeos/posts desde el backend (revisión más estricta). |
| **Display API** | Mostrar contenido de TikTok en tu sitio. |
| **Embed Videos** | Embeber vídeos de TikTok. |
| **Green Screen Kit** | Efectos en vídeo (no prioritario para ofertas). |
| **Data Portability API** | Exportar datos del usuario. |
| **Research API** | Uso académico/investigación. |
| **Commercial Content API** | Contenido patrocinado / anuncios. |

Para empezar solo con **link en bio** y compartir ofertas, suele bastar con **Share Kit** (si lo activas) o sin productos hasta que decidas integrar algo.

## 4. Verificación de URLs (si aplica)

Para **Web** y para algunos productos (p. ej. Content Posting API, Link Sharing):

- En la app, abre **URL properties** (arriba en la página de la app).
- Verifica:
  - **Web/Desktop URL** (tu sitio oficial).
  - **Privacy Policy URL** y **Terms of Service URL** si los piden.
- Puedes verificar por **dominio** (ej. `mayoreo.cloud`) o por **prefijo de URL** (subes un fichero que te dan en la raíz del sitio).

## 5. Enviar a revisión

1. En el panel izquierdo entra en **App review**.
2. Rellena la información que pidan:
   - Explica cómo usas cada producto y scope en tu web/app (ej. “Share Kit para compartir ofertas desde mayoreo.cloud”).
   - Sube al menos un vídeo demo mostrando el flujo de principio a fin (máx. 5 vídeos, 50 MB cada uno).
3. Envía la app a revisión.

El estado aparecerá en **Production** (Draft → In review → Live). La revisión puede tardar varios días. Cuando pase a **Live**, podrás usar las APIs en producción.

## 6. Guardar credenciales en el proyecto

Cuando tengas **Client key** y **Client secret**:

1. **Panel Admin** → **Marketplaces** → edita el marketplace **TikTok** (slug `tiktok`).
2. En la sección **TikTok (Development / perfil)** (o en una nueva sección “Credenciales”) guarda:
   - Client Key
   - Client Secret (como campo tipo contraseña)
3. O en `.env`:
   ```env
   TIKTOK_CLIENT_KEY=tu_client_key
   TIKTOK_CLIENT_SECRET=tu_client_secret
   ```

Si quieres, en el código se pueden leer desde `Marketplace::tiktokActivo()` (configuración) o desde `config('services.tiktok')` / `env('TIKTOK_CLIENT_KEY')`.

## Enlaces útiles

- [Register Your App (doc oficial)](https://developers.tiktok.com/doc/getting-started-create-an-app)
- [Manage apps](https://developers.tiktok.com/apps)
- [App Review Guidelines](https://developers.tiktok.com/doc/app-review-guidelines)
- [Sandbox mode](https://developers.tiktok.com/doc/add-a-sandbox) (probar sin pasar revisión)
