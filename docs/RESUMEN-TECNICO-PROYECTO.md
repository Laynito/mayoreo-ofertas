# Resumen técnico del proyecto mayoreo.cloud

Documento de referencia para colaboradores e IA: estado actual de la base de datos, modelos, panel Filament, lógica de afiliados y scraper.

---

## 1. Base de datos

### Tablas principales

| Tabla | Descripción |
|-------|-------------|
| **productos** | Catálogo de ofertas por tienda. Identificador único: `tienda_origen` + `sku_tienda`. |
| **historial_precios** | Registro histórico de precios por producto (para evolución y gráficas). |
| **users** | Usuarios del panel (Laravel auth). |
| **jobs** | Cola de trabajos (Laravel). |
| **cache** | Cache (Laravel). |
| **sessions** / **password_reset_tokens** | Sesiones y reseteo de contraseña. |

### Tabla `productos`

- **id** (PK)
- **tienda_origen** (string) — Ej: Walmart, Amazon, Liverpool, Coppel, Mercado Libre, Otro.
- **sku_tienda** (string, index) — SKU en la tienda.
- **nombre** (string)
- **imagen_url** (string 2048, nullable)
- **precio_original** (decimal 12,2, default 0)
- **precio_oferta** (decimal 12,2, nullable)
- **porcentaje_ahorro** (decimal 5,2, nullable)
- **stock_disponible** (unsigned int, default 0)
- **ultima_actualizacion_precio** (timestamp, nullable)
- **url_original** (string 2048, nullable)
- **url_afiliado** (string 2048, nullable) — Enlace con ID Admitad (se puede guardar precalculado).
- **permite_descuento_adicional** (boolean, default **true**) — **Restricción**: si es `false`, no se aplica ningún descuento adicional sobre el precio de oferta.
- **timestamps**
- **Unique**: `(tienda_origen, sku_tienda)`

### Tabla `historial_precios`

- **id** (PK)
- **producto_id** (FK → productos, onDelete cascade)
- **precio_original** (decimal 12,2)
- **precio_oferta** (decimal 12,2, nullable)
- **porcentaje_ahorro** (decimal 5,2, nullable)
- **registrado_en** (timestamp)
- **timestamps**
- **Index**: `(producto_id, registrado_en)` — Para consultas por producto y tiempo.

### Tabla `tiendas`

**No existe.** La tienda no es una entidad separada; se usa el campo **tienda_origen** en `productos` (string con valores fijos en el formulario: Walmart, Amazon, Liverpool, etc.).

### Confirmación restricción de descuentos

El campo **permite_descuento_adicional** existe en `productos`, es boolean y por defecto `true`. Su uso está implementado en la lógica de negocio (ver sección 4).

---

## 2. Modelos

### `App\Models\Producto`

- **Relaciones**
  - **historialPrecios()** — `HasMany(HistorialPrecio::class)`.
- **Fillable**: tienda_origen, sku_tienda, nombre, imagen_url, precio_original, precio_oferta, porcentaje_ahorro, stock_disponible, ultima_actualizacion_precio, url_original, url_afiliado, permite_descuento_adicional.
- **Casts**: precios y porcentaje a `decimal:2`, `ultima_actualizacion_precio` a `datetime`, `permite_descuento_adicional` a `boolean`.
- **Accessors (lógica personalizada)**
  - **precio_final** — Precio a mostrar al usuario. Delega en `CalculadoraOfertas::precioFinal($producto, null)` y respeta `permite_descuento_adicional`.
  - **url_afiliado_completa** — URL de afiliado lista para usar: si existe `url_afiliado` la devuelve; si no, genera una desde `url_original` con el ID de Admitad vía `CalculadoraOfertas::urlAfiliadoAdmitad()`.

### `App\Models\HistorialPrecio`

- **Relaciones**
  - **producto()** — `BelongsTo(Producto::class)`.
- **Fillable**: producto_id, precio_original, precio_oferta, porcentaje_ahorro, registrado_en.
- **Casts**: precios y porcentaje a `decimal:2`, `registrado_en` a `datetime`.

### `App\Models\User`

- Modelo estándar de Laravel (auth). No tiene relaciones con productos ni historial.

---

## 3. Panel administrativo (Filament 3)

### Recursos registrados

Solo existe un Resource:

- **ProductoResource** (`App\Filament\Resources\ProductoResource`)
  - **Modelo**: `App\Models\Producto`
  - **Rutas**: listado (`/`), crear (`/create`), ver (`/{record}`), editar (`/{record}/edit`).
  - **Navegación**: icono `shopping-bag`, etiqueta "Productos".

### Funciones del ProductoResource

**Formulario (Create/Edit):**

- **General**: Tienda origen (Select: Walmart, Amazon, Liverpool, Coppel, Mercado Libre, Otro), SKU tienda, nombre, URL de imagen.
- **Precios**: Precio original, precio oferta, % ahorro, stock disponible, última actualización de precio. **Sí permite editar todos los precios.**
- **Afiliación**: URL original, URL afiliado (Admitad).
- **Configuraciones**: Toggle **Permitir descuento adicional** (permite_descuento_adicional), con texto de ayuda.

**Tabla (Listado):**

- Columnas: imagen, nombre, tienda, precio actual (calculado: oferta u original), badge “Súper Oferta” (si `porcentaje_ahorro` > 50), % ahorro, stock.
- Ordenación en todas las columnas relevantes.
- Búsqueda en nombre.

**Filtros:**

- **Tienda** — SelectFilter por tienda_origen (mismas opciones que el formulario).
- **Con stock** — `stock_disponible > 0`.
- **Sin stock** — `stock_disponible <= 0`.

**Acciones:**

- Ver y editar por fila.
- Eliminar en masa (BulkActionGroup).

### Widgets del escritorio

No hay widgets personalizados. El panel usa los por defecto de Filament:

- `Widgets\AccountWidget`
- `Widgets\FilamentInfoWidget`

El provider hace `discoverWidgets(in: app_path('Filament/Widgets'))`, pero **no existe la carpeta `app/Filament/Widgets`**, por lo que no hay widgets de proyecto en el dashboard.

### Panel

- **ID**: `admin`
- **Path**: `/admin`
- **Login**: habilitado.
- **Brand**: Mayoreo Cloud, color primario Amber.

---

## 4. Lógica de negocio

### Inyección de ID Admitad

- **Ubicación**: `App\Services\CalculadoraOfertas`.
- **Método**: `urlAfiliadoAdmitad(string $urlOriginal, string $idAdmitad, array $params = []): string`.
  - Añade el parámetro `subid` (y opcionalmente otros) a la URL original.
  - Usa `?` o `&` según si la URL ya tiene query string.
- **Origen del ID**: `config('services.admitad.id')` que lee `env('ADMITAD_SUBID', '')` — ver `config/services.php`.
- **Uso en la app**:
  - El modelo `Producto` usa este método en el accessor **url_afiliado_completa**: si no hay `url_afiliado` guardada, construye la URL con `CalculadoraOfertas::urlAfiliadoAdmitad($urlOriginal, $idAdmitad)`.
  - En la vista pública el enlace “Comprar oferta” usa `$producto->url_afiliado_completa ?? $producto->url_original`.

### Restricción de descuentos por producto

- **Ubicación**: `App\Services\CalculadoraOfertas`.
- **Método**: `precioFinal(Producto $producto, ?float $descuentoAdicionalPorcentaje = null): float`.
- **Lógica**:
  - Precio base = `precio_oferta ?? precio_original`.
  - Si **permite_descuento_adicional** es **false**: se devuelve el precio base sin aplicar ningún descuento adicional.
  - Si es **true** y se pasa un porcentaje de descuento adicional válido (> 0), se aplica sobre el precio base y se devuelve el resultado.
- **Uso**: El accessor `Producto::precio_final` llama a `precioFinal($this, null)` para mostrar siempre el precio correcto según la restricción. Cualquier regla de oferta o descuento extra debe usar este servicio y respetará `permite_descuento_adicional`.

---

## 5. Estado del scraper / comandos

- **No existe** la carpeta `app/Console` ni `app/Console/Commands`.
- En `routes/console.php` solo está el comando por defecto `inspire`.
- **No hay** ningún comando Artisan relacionado con Walmart ni con rastreo de ofertas de otras tiendas.

Por tanto, el **motor de rastreo (scraper/bot)** está pendiente de implementar (por ejemplo un comando tipo `RastrearOfertasWalmart` que actualice `productos` y registre en `historial_precios`).

---

## 6. Resumen por secciones (checklist para IA)

| Sección | Estado |
|--------|--------|
| **Base de datos** | Tablas `productos` e `historial_precios` creadas. No hay tabla `tiendas`; la tienda es `tienda_origen` en productos. Campo `permite_descuento_adicional` existe y está en uso. |
| **Modelos** | `Producto` (HasMany historialPrecios; accessors precio_final y url_afiliado_completa). `HistorialPrecio` (BelongsTo producto). |
| **Panel Filament** | Un Resource: ProductoResource (CRUD completo, edición de precios, filtros por tienda y stock, badge Súper Oferta). Sin widgets propios. |
| **Lógica afiliados** | `CalculadoraOfertas::urlAfiliadoAdmitad()` en `app/Services/CalculadoraOfertas.php`. ID desde `config('services.admitad.id')` / `ADMITAD_SUBID`. |
| **Restricción descuentos** | `CalculadoraOfertas::precioFinal()` respeta `permite_descuento_adicional`; si es false no se aplica descuento adicional. |
| **Scraper** | No implementado; no hay comandos en Console. |

---

*Generado para mayoreo.cloud — Laravel 12, Filament 3.*
