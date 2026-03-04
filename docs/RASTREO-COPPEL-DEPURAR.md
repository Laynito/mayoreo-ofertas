# Depurar y volver a subir ofertas (Coppel u otra tienda)

## 1. Depurar la lista

### Ver productos actuales de Coppel en la BD
```bash
cd /home/mayoreo/htdocs/mayoreo-cloud
php artisan tinker
```
```php
// Contar productos Coppel
\App\Models\Producto::where('tienda_origen', 'Coppel')->count();

// Listar algunos (nombre, precios, imagen)
\App\Models\Producto::where('tienda_origen', 'Coppel')->take(10)->get(['sku_tienda', 'nombre', 'precio_original', 'precio_oferta', 'imagen_url', 'url_original']);

// Ver si hay precios en 0 o imágenes vacías
\App\Models\Producto::where('tienda_origen', 'Coppel')->where(function ($q) { $q->where('precio_original', 0)->orWhereNull('imagen_url'); })->count();
```
Salir de tinker: `exit`

### Ver el flujo RSC que recibe el motor
- El último HTML/respuesta de Coppel se guarda en:  
  **`storage/logs/debug_coppel.html`**
- Ahí puedes revisar el formato de los objetos (price, offerPrice, title, image, partNumber, etc.).

### Logs del rastreo
- **`storage/logs/laravel.log`**: líneas con `CoppelMotor` o `RastrearTienda` (productos extraídos, creados/actualizados, errores).

---

## 2. Volver a subir las ofertas

### Opción A: Solo volver a ejecutar (actualizar)
No borras nada; el comando actualiza productos existentes y agrega nuevos:
```bash
php artisan rastreo:tienda Coppel
```

### Opción B: Limpiar productos de Coppel y volver a importar
Útil si quieres “empezar de cero” para esa tienda (por ejemplo tras cambiar el motor).

**Paso 1 – Borrar productos de Coppel y su historial de precios**
```bash
php artisan tinker
```
```php
$ids = \App\Models\Producto::where('tienda_origen', 'Coppel')->pluck('id');
\App\Models\HistorialPrecio::whereIn('producto_id', $ids)->delete();
\App\Models\Producto::where('tienda_origen', 'Coppel')->delete();
exit
```

**Paso 2 – Volver a rastrear**
```bash
php artisan rastreo:tienda Coppel
```

Así se crean de nuevo todos los productos de Coppel y sus registros en historial cuando corresponda.

---

## 3. Comando para limpiar y volver a subir

Puedes usar el comando incluido en el proyecto:

**Una sola tienda (ej. Coppel):**
```bash
# Pide confirmación antes de borrar
php artisan rastreo:limpiar-tienda Coppel

# Sin confirmación (útil en scripts)
php artisan rastreo:limpiar-tienda Coppel --force
```

**Todas las tiendas (todos los productos y todo el historial):**
```bash
php artisan rastreo:limpiar-tienda all
# o:  rastreo:limpiar-tienda todo
# o:  rastreo:limpiar-tienda "*"

php artisan rastreo:limpiar-tienda all --force   # sin confirmación
```

Luego vuelve a rastrear por tienda:
```bash
php artisan rastreo:tienda Coppel
php artisan rastreo:tienda Walmart
# etc.
```
