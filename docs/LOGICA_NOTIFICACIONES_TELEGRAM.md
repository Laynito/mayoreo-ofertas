# Lógica de notificaciones por Telegram

## Resumen

1. **Rastreo** guarda en BD todos los productos con descuento real (`precio_oferta < precio_original`).
2. **Encolado** decide cuántos de esos productos se meten en la cola para enviar por Telegram.
3. **Cada job** envía un producto al canal Premium o al canal Free según el % de ahorro.

---

## 1. Qué productos se encolan

**Por defecto** solo se encolan ofertas **nuevas o actualizadas** en este rastreo (no se repiten las que ya estaban). Para encolar todas las ofertas con descuento cada vez, usa la opción `--notificar-todos` al ejecutar el comando de rastreo.

Además, se encolan solo los que cumplen **todas** estas condiciones:

| Condición | Dónde se configura |
|-----------|--------------------|
| `tienda_origen` = la tienda que estás rastreando | — |
| `precio_oferta` < `precio_original` (descuento real) | — |
| `precio_oferta` no nulo | — |
| Si está activo: `permite_descuento_adicional = true` | **Configuración → Notificaciones** → "Solo productos con descuento adicional" |

**Si ves menos productos encolados que los que tienes con descuento:**  
Revisa el toggle **"Solo productos con descuento adicional"**. Si está **activado**, solo se notifican productos que en BD tienen `permite_descuento_adicional = true`. Para recibir **todas** las ofertas con descuento real, desactívalo.

---

## 2. A qué canal va cada oferta (Premium vs Free)

| % de ahorro | Canal |
|-------------|--------|
| **≥ 20%** (umbral Premium) | Premium (+ teaser en Free) |
| **10–19%** | Free |
| **0–9%** | Premium (el canal premium recibe todas, también las de poco descuento) |

Configuración:

- **Porcentaje mínimo** (ej. 10): debajo de este % no se envía al canal Free; sí al Premium.
- **Porcentaje Premium** (ej. 20): a partir de este % va a Premium y se envía el teaser "oferta bomba" al Free.

---

## 3. Por qué puedes recibir “pocos” mensajes

- **Menos encolados de lo esperado:**  
  - Filtro **"Solo productos con descuento adicional"** activo y varios productos con `permite_descuento_adicional = false`.  
  - Solución: desactivar ese toggle en Configuración si quieres notificar todas las ofertas con descuento.

- **Encolados bien (ej. 48) pero pocos mensajes en Telegram:**  
  - Los **jobs están fallando** (imagen, Telegram API, etc.).  
  - Revisa `storage/logs/laravel.log` (busca `EnviarOfertaTelegramJob`) y `php artisan queue:failed`.  
  - Prueba en **Configuración → Notificaciones** desactivar **"Enviar imágenes"** para enviar solo texto y reducir fallos.

---

## 4. Comandos útiles

```bash
# Ver cuántos jobs fallaron y por qué
php artisan queue:failed

# Reintentar todos los fallidos
php artisan queue:retry all
```
