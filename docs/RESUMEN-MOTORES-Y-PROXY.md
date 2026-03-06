# Resumen: motores de rastreo, ofertas y proxy

Revisión según logs (`laravel.log`, `worker.log`) y código de motores. Actualizado para tener una vista unificada de por qué algunas tiendas no envían ofertas y cuáles se benefician de proxy.

---

## 1. Estado por tienda (según logs y código)

| Tienda | ¿Envía ofertas? | Problema en logs | ¿Usa PROXY_URL? | Recomendación |
|--------|------------------|------------------|------------------|----------------|
| **Calimax** | Sí | Ninguno; a veces "Captura no disponible" si falta Chromium. | Sí (BaseMotorRastreador) | Sin cambios. Asegurar `BROWSERSHOT_CHROME_PATH` para capturas. |
| **Coppel** | Sí | Productos extraídos por RSC/__STATE__. Ofertas se envían. | Sí (config proxy en opciones) | Sin cambios. |
| **Elektra** | Sí (pocas) | API catalog a veces devuelve 1 producto; HTML ofertas-semanales como respaldo. | Sí (conProxySiTexto) | Sin cambios. Revisar si la API devuelve más con otro CP o proxy. |
| **Walmart** | No | 404 en `/super/ofertas`; "Verifica tu identidad" en búsqueda (bloqueo anti-bot). Motor puede quedar marcado bloqueado. | Sí (conProxySiTexto) | **Probar con proxy**: definir `PROXY_URL` en `.env` (proxy residencial/rotativo). Reactivar motor en Admin → Estado de motores. |
| **Sams Club** | No | API 403 "You are not Authorized"; 404 en `/ofertas` y `/s/rebajas`. | Sí (conProxySiTexto) | **Probar con proxy**: mismo que Walmart. Reactivar si está bloqueado. |
| **Amazon** | No / prueba | 404 en `/gp/deals`; a veces solo "producto de prueba" local. | Sí (conProxy) | **Probar con proxy**. Posible cambio de URL o estructura; con proxy a veces se evita bloqueo por IP. |
| **Costco** | No | 200 OK pero "no se extrajeron productos" (cambio de estructura/selectores). | Sí (conProxySiTexto) | Actualizar parser/selectores del motor. Proxy puede ayudar si además hay bloqueo por IP. |
| **Mercado Libre** | No | 403 en API/HTML. | Sí (conProxySiTexto) | Definir `PROXY_URL` en `.env` y reactivar motor si está bloqueado. |
| **Soriana** | No | 403 Forbidden en `/ofertas`. | No (no usa HttpRastreador) | **Añadir proxy** en SorianaMotor (usar `HttpRastreador::conProxySiTexto` o config proxy) y definir `PROXY_URL`. |
| **Office Depot** | No | Productos extraídos pero ninguno con precio válido (todos filtrados). | No | Revisar filtros de precio en el motor; no es bloqueo HTTP. Proxy solo si además hay 403. |
| **AliExpress** | No | No se extrajeron productos (bloqueo o estructura). | No | Revisar selectores y/o añadir proxy si las peticiones devuelven 403/captcha. |
| **Liverpool** | — | No revisado en logs recientes. | No | Si da 403, añadir proxy. |
| **Bodega Aurrera** | — | No revisado en logs recientes. | No | Igual que Liverpool. |
| **Chedraui** | — | No revisado en logs recientes. | No | Igual que Liverpool. |

---

## 2. Qué motores usan proxy (PROXY_URL)

Si defines `PROXY_URL` en `.env`, estos motores ya lo usan para peticiones de texto (HTML/API); las imágenes se piden sin proxy cuando aplica `conProxySiTexto`:

- **BaseMotorRastreador** (Calimax y cualquiera que use `realizarPeticion`)
- **WalmartMotor**
- **SamsClubMotor**
- **CostcoMotor**
- **CoppelMotor** (proxy en opciones de petición)
- **ElektraMotor**
- **AmazonMexicoMotor** (usa `conProxy`; todo el tráfico por proxy si está definido)
- **MercadoLibreMotor** (usa `conProxySiTexto` en la petición a la API MLM)

No usan proxy actualmente:

- **SorianaMotor**, **OfficeDepotMotor**, **AliExpressMotor**, **LiverpoolMotor**, **BodegaAurreraMotor**, **ChedrauiMotor** (no integrados con `HttpRastreador` / `PROXY_URL`)

---

## 3. Por qué no envían ofertas (resumen)

1. **Bloqueo 403 / “Verifica tu identidad”**  
   La tienda rechaza la IP o el cliente (anti-bot).  
   **Solución:** Proxy (idealmente residencial). Tiendas típicas: Walmart, Sams Club, Amazon, Mercado Libre, Soriana.

2. **404 o URL/estructura cambiada**  
   La ruta de ofertas o la página cambió; el motor no encuentra datos.  
   **Solución:** Actualizar URLs o selectores en el motor. Proxy no arregla 404.

3. **200 pero “no se extrajeron productos”**  
   La estructura HTML/JSON cambió; los selectores del parser ya no coinciden.  
   **Solución:** Revisar y actualizar el motor (Costco, Office Depot, AliExpress, etc.). Proxy solo si además hay bloqueo.

4. **Motor marcado “bloqueado”**  
   Tras varios 403, `EstadoMotorService` marca el motor como bloqueado y `rastreo:todas` lo omite.  
   **Solución:** Configurar proxy, luego en **Admin → Estado de motores** reactivar la tienda.

---

## 4. Cómo activar proxy para los que ya lo soportan

En `.env`:

```env
PROXY_URL=http://usuario:contraseña@host-proxy:puerto
```

Reiniciar worker (y si aplica, el proceso que ejecuta el rastreo). No hace falta cambio de código en los motores que ya usan `HttpRastreador` o `config('services.proxy_url')`.

Para tiendas que **aún no usan proxy** (Mercado Libre, Soriana, Office Depot, AliExpress, etc.), hace falta modificar el motor para usar `HttpRastreador::conProxySiTexto()` o la opción `proxy` en las peticiones y volver a probar.

---

## 5. Comandos útiles

```bash
# Ver últimos fallos por motor en logs
grep -E "Motor:|403|404|No se encontraron|extracción fallida|bloqueo" storage/logs/laravel.log | tail -100

# Rastrear una tienda a mano (ej. Walmart con proxy ya configurado)
php artisan rastreo:tienda Walmart

# Ver estado de motores (bloqueados)
# En Filament: Admin → Estado de motores (tabla estado_motores)
```

---

## 6. Resumen rápido

| Necesitan proxy (403/bloqueo) | Necesitan actualizar código (estructura/parser) | Funcionan bien |
|-------------------------------|--------------------------------------------------|----------------|
| Walmart, Sams Club, Amazon, Mercado Libre, Soriana | Costco, Office Depot (filtro precio), AliExpress | Calimax, Coppel, Elektra |

Si configuras un proxy residencial y `PROXY_URL`, conviene probar en este orden: **Walmart**, **Sams Club**, **Amazon**; luego **Soriana** cuando su motor use proxy. **Costco** y **Office Depot** requieren sobre todo ajuste de selectores/lógica de precios en código.
