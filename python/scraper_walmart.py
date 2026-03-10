#!/usr/bin/env python3
"""
Scraper Walmart México - Misma lógica de SKUs deterministas que Mercado Libre.
Usa urlparse para normalizar URLs (sin ? ni #), SKU por ID numérico /ip/.../ID o MD5.
Guarda en la misma tabla productos con tienda='Walmart' y ON DUPLICATE KEY UPDATE por sku.

Credenciales de la base de datos (producción/VPS) desde .env:
  DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
Así puedes ejecutar el script en tu PC y que suba a la BD del VPS.
"""

import hashlib
import json
import os
import random
import re
import sys
import time
from datetime import datetime
from pathlib import Path
from urllib.parse import urlparse

# Cargar .env de Laravel (directorio raíz del proyecto)
PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"
# Capturas de debug (captcha, sin productos) — misma ruta que Mercado Libre
DEBUG_DIR = PROJECT_ROOT / "storage" / "logs" / "scraper_debug"
# Perfil persistente: caché y cookies en disco para parecer un navegador real entre ejecuciones
WALMART_PROFILE_DIR = PROJECT_ROOT / "storage" / "app" / "walmart_browser_profile"


def _cargar_env():
    if not ENV_PATH.exists():
        return
    from dotenv import dotenv_values
    _env = dotenv_values(ENV_PATH)
    for k, v in (_env or {}).items():
        if k in os.environ:
            continue
        if v is None:
            continue
        v = str(v).strip()
        if (v.startswith("'") and v.endswith("'")) or (v.startswith('"') and v.endswith('"')):
            v = v[1:-1]
        os.environ[k] = v


_cargar_env()

import mysql.connector
from playwright.sync_api import sync_playwright
import playwright_stealth


# --- Configuración Walmart México ---
BASE_WALMART = "https://www.walmart.com.mx"
# URL Precios Bajos / Ofertas (configurable por env)
URL_PRECIOS_BAJOS = os.environ.get(
    "WALMART_PRECIOS_BAJOS_URL",
    "https://www.walmart.com.mx/browse/especiales/360013_300279_300286?co_zn=contentSN1-sub-navegation&co_ty=WEB-OHWM-subnavegation&co_nm=Homepage&co_id=precios-bajos&co_or=ahorros",
)
TIENDA = "Walmart"

# Selectores para cuando la página real carga (tras pasar "Verifica tu identidad").
# Walmart suele usar data-testid, data-automation y clases tipo product-tile / ProductCard.
CARD_SELECTOR = (
    "[data-testid*='product-card'], [data-testid*='productCard'], [data-automation*='product-tile'], "
    "[data-testid*='product'], [class*='product-tile'], [class*='ProductCard'], "
    "article[class*='product'], li[class*='product'], [class*='GridItem'], "
    "div[class*='product-card'], section:has(a[href*='/ip/'])"
)
SELECTOR_LINK = "a[href*='/ip/']"
SELECTOR_NOMBRE = (
    "[data-testid*='product-title'], [data-automation*='product-title'], "
    "h2, h3, [class*='title'], [class*='Title'], [class*='product-name']"
)
SELECTOR_PRECIO = (
    "[data-testid*='current-price'], [data-automation*='current-price'], "
    "[class*='price'], [class*='Price'], span[class*='current'], [data-testid*='price']"
)
SELECTOR_PRECIO_ANTERIOR = "s, [class*='strike'], [class*='original'], [class*='before'], [data-testid*='original-price']"
SELECTOR_IMAGEN = "img[src*='walmart'], img[src*='walmartimages'], img[data-src], img"

# User-Agent de Chrome actualizado (reduce frecuencia de captcha)
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
]
# Selectores que indican que la página de productos cargó (para auto-detección tras captcha)
SELECTORES_PAGINA_PRODUCTOS = [
    "a[href*='/ip/']",
    "[data-testid='variant-tile']",
    "[data-testid*='product-tile']",
    "[data-automation*='product-tile']",
]


def _sku_desde_url(url: str) -> str:
    """
    SKU estable para Walmart: URL normalizada (sin ? ni #).
    - Si la ruta es /ip/.../ID (ID numérico), usa 'wm_ID' como SKU.
    - Si no, usa MD5 de la URL normalizada (determinista).
    """
    if not (url or "").strip():
        return hashlib.md5(b"").hexdigest()[:16]
    parsed = urlparse(url)
    path = (parsed.path or "").rstrip("/")
    url_normalizada = f"{parsed.scheme or 'https'}://{parsed.netloc or ''}{path}"
    # Walmart: /ip/slug-producto/0075010011 -> ID 0075010011
    if "/ip/" in path:
        segments = [s for s in path.split("/") if s]
        if segments and segments[-1].isdigit():
            return "wm_" + segments[-1]
    return hashlib.md5(url_normalizada.encode("utf-8")).hexdigest()[:16]


def _url_producto_absoluta(url: str | None) -> str | None:
    """URL del producto absoluta; quitar query y fragmento para consistencia."""
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        u = "https:" + u
    if u.startswith("/"):
        u = BASE_WALMART + u
    parsed = urlparse(u)
    return f"{parsed.scheme or 'https'}://{parsed.netloc or ''}{parsed.path or ''}".rstrip("/")


def _url_imagen_absoluta(url: str | None) -> str | None:
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        return "https:" + u
    if u.startswith("http://") or u.startswith("https://"):
        return u
    if u.startswith("/"):
        return BASE_WALMART + u
    return BASE_WALMART + "/" + u


def parse_precio(texto: str) -> float | None:
    """Extrae número decimal de un string de precio."""
    if not texto:
        return None
    s = re.sub(r"[^\d,.\s]", "", texto.strip())
    s = s.replace(" ", "")
    if "," in s and "." in s:
        if s.rfind(".") > s.rfind(","):
            s = s.replace(",", "")
        else:
            s = s.replace(".", "").replace(",", ".")
    elif "," in s:
        if s.count(",") == 1 and len(s.split(",")[-1]) <= 2:
            s = s.replace(",", ".")
        else:
            s = s.replace(",", "")
    try:
        return float(s)
    except ValueError:
        return None


def _query_one(container, selectors: str):
    for sel in (s.strip() for s in selectors.split(",")):
        el = container.query_selector(sel)
        if el:
            return el
    return None


def extraer_productos(page, card_selector: str | None = None) -> list[dict]:
    """Extrae productos de la página de listado Walmart."""
    selector = card_selector or CARD_SELECTOR
    cards = page.query_selector_all(selector)
    # Si no hay cards por selector de tarjeta, intentar por enlaces /ip/
    if not cards:
        links = page.query_selector_all(SELECTOR_LINK)
        seen_urls = set()
        productos = []
        for link_el in links:
            try:
                href = link_el.get_attribute("href")
                if not href or "/ip/" not in href:
                    continue
                url_producto = _url_producto_absoluta(href)
                if not url_producto or url_producto in seen_urls:
                    continue
                seen_urls.add(url_producto)
                sku = _sku_desde_url(url_producto)
                # Nombre desde texto del enlace; precio desde contenedor (innerText del padre)
                nombre = (link_el.inner_text() or "").strip() or "Sin nombre"
                try:
                    container_text = link_el.evaluate("el => (el.closest('article, li, [class*=\"product\"], [class*=\"card\"], div') || el.parentElement)?.innerText || ''")
                except Exception:
                    container_text = ""
                precio_actual = parse_precio(container_text) or 0.0
                precio_original = None
                descuento = 0
                url_imagen = None
                productos.append({
                    "nombre": nombre[:500],
                    "sku": sku,
                    "precio_actual": round(precio_actual, 2),
                    "precio_original": round(precio_original, 2) if precio_original else None,
                    "descuento": descuento,
                    "url_producto": url_producto,
                    "url_imagen": url_imagen,
                    "tienda": TIENDA,
                })
            except Exception as e:
                print(f"[WARN] Error extrayendo producto: {e}", file=sys.stderr)
                continue
        return productos

    productos = []
    seen_skus = set()
    for card in cards:
        try:
            link_el = _query_one(card, SELECTOR_LINK)
            url_producto_raw = link_el.get_attribute("href") if link_el else ""
            if not url_producto_raw or "/ip/" not in url_producto_raw:
                continue
            url_producto = _url_producto_absoluta(url_producto_raw)
            sku = _sku_desde_url(url_producto)
            if sku in seen_skus:
                continue
            seen_skus.add(sku)
            name_el = _query_one(card, SELECTOR_NOMBRE)
            nombre = (name_el.inner_text() or "").strip() if name_el else ""
            price_el = _query_one(card, SELECTOR_PRECIO)
            precio_actual = parse_precio(price_el.inner_text()) if price_el else 0.0
            old_el = _query_one(card, SELECTOR_PRECIO_ANTERIOR)
            precio_original = parse_precio(old_el.inner_text()) if old_el else None
            descuento = 0
            if precio_original and precio_original > 0 and precio_actual < precio_original:
                descuento = int(round((1 - precio_actual / precio_original) * 100))
            img_el = _query_one(card, SELECTOR_IMAGEN)
            raw_img = (img_el.get_attribute("src") or (img_el.get_attribute("data-src") if img_el else None)) if img_el else None
            url_imagen = _url_imagen_absoluta(raw_img) if raw_img else None
            productos.append({
                "nombre": nombre or "Sin nombre",
                "sku": sku,
                "precio_actual": round(precio_actual, 2),
                "precio_original": round(precio_original, 2) if precio_original is not None else None,
                "descuento": descuento,
                "url_producto": url_producto,
                "url_imagen": url_imagen,
                "tienda": TIENDA,
            })
        except Exception as e:
            print(f"[WARN] Error extrayendo card: {e}", file=sys.stderr)
            continue
    return productos


def _get_walmart_session_from_db() -> dict | None:
    """
    Lee de la tabla marketplaces la sesión y credenciales de Walmart (slug=walmart).
    Usa cookies_json; si no hay, usa session_data (legacy).
    """
    try:
        conn = _get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT cookies_json, session_data, email, password, affiliate_user, affiliate_password FROM marketplaces WHERE slug = %s LIMIT 1",
            ("walmart",),
        )
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        return row
    except Exception as e:
        print(f"[WARN] No se pudo leer sesión Walmart de BD: {e}", file=sys.stderr)
        return None


def _get_cookies_from_row(row: dict | None) -> list | None:
    """Extrae lista de cookies desde cookies_json o session_data (legacy)."""
    if not row:
        return None
    raw = row.get("cookies_json") or row.get("session_data")
    if not raw:
        return None
    try:
        data = json.loads(raw)
        return data if isinstance(data, list) else None
    except (json.JSONDecodeError, TypeError):
        return None


def _guardar_sesion_walmart_en_bd(cookies: list) -> None:
    """Guarda las cookies en marketplaces.cookies_json (y session_data por compatibilidad) para slug=walmart."""
    if not cookies:
        return
    try:
        conn = _get_db_connection()
        cursor = conn.cursor()
        data = json.dumps(cookies, ensure_ascii=False)
        cursor.execute(
            "UPDATE marketplaces SET cookies_json = %s, session_data = %s WHERE slug = %s",
            (data, data, "walmart"),
        )
        conn.commit()
        cursor.close()
        conn.close()
        print("[OK] Sesión Walmart guardada en el panel (marketplaces.cookies_json).")
    except Exception as e:
        print(f"[WARN] No se pudo guardar sesión en BD: {e}", file=sys.stderr)


def _get_db_connection():
    """Conexión MySQL con variables del .env (IP/host del VPS, usuario, contraseña)."""
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    database = os.environ.get("DB_DATABASE", "mayoreo_cloud")
    user = os.environ.get("DB_USERNAME", "root")
    password = os.environ.get("DB_PASSWORD", "")
    if password and (password.startswith("'") or password.startswith('"')):
        password = password.strip("'\"").strip()
    return mysql.connector.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
    )


def guardar_en_mysql(productos: list[dict]) -> None:
    """Inserta o actualiza por SKU o url_producto_hash (ON DUPLICATE KEY UPDATE)."""
    if not productos:
        return
    import hashlib
    conn = _get_db_connection()
    cursor = conn.cursor()
    sql = """
    INSERT INTO productos (nombre, sku, precio_actual, precio_original, descuento, url_producto, url_producto_hash, url_imagen, tienda, created_at, updated_at)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        precio_actual   = VALUES(precio_actual),
        precio_original = VALUES(precio_original),
        descuento       = VALUES(descuento),
        url_imagen      = VALUES(url_imagen),
        updated_at      = NOW()
    """
    for p in productos:
        url_hash = hashlib.sha256((p["url_producto"] or "").encode()).hexdigest()
        cursor.execute(sql, (
            p["nombre"],
            p["sku"],
            p["precio_actual"],
            p["precio_original"],
            p["descuento"],
            p["url_producto"],
            url_hash,
            p["url_imagen"],
            p["tienda"],
        ))
    conn.commit()
    cursor.close()
    conn.close()
    print(f"[OK] Guardados/actualizados {len(productos)} productos Walmart en MySQL.")


def _sku_de_producto(p: dict) -> str | None:
    sku = (p.get("sku") or "").strip()
    if sku:
        return sku
    url = (p.get("url_producto") or p.get("url") or "").strip()
    if url:
        return _sku_desde_url(url)
    return None


def _scroll_para_lazy_load(page, pasos: int = 6):
    for i in range(pasos):
        page.evaluate("window.scrollBy(0, window.innerHeight * 0.6)")
        page.wait_for_timeout(random.randint(400, 900))
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(random.randint(1000, 2500))


def _tiene_captcha_walmart(page) -> bool:
    """Detecta la página 'Verifica tu identidad' (PerimeterX) de Walmart."""
    try:
        title = (page.title() or "").strip()
        if "Verifica tu identidad" in title:
            return True
        if page.get_by_text("Verifica tu identidad", exact=False).first.is_visible(timeout=2000):
            return True
        return False
    except Exception:
        return False


def _beep_captcha() -> None:
    """Alerta sonora de sistema cuando se detecta captcha (no hace falta estar pegado a la pantalla)."""
    try:
        for _ in range(5):
            sys.stdout.write("\a")
            sys.stdout.flush()
            time.sleep(0.25)
    except Exception:
        pass


def _pagina_productos_visible(page) -> bool:
    """Comprueba si algún selector de la página de productos es visible."""
    for sel in SELECTORES_PAGINA_PRODUCTOS:
        try:
            el = page.query_selector(sel)
            if el and el.is_visible():
                return True
        except Exception:
            continue
    return False


def _esperar_pagina_productos_auto(page, intervalo: float = 2.0, tiempo_max: float = 600.0) -> bool:
    """
    Revisa cada `intervalo` segundos si la página de productos es visible.
    Retorna True si se detectó, False si pasó `tiempo_max` sin verla.
    """
    inicio = time.monotonic()
    while (time.monotonic() - inicio) < tiempo_max:
        if _pagina_productos_visible(page):
            return True
        time.sleep(intervalo)
    return False


def _guardar_debug_screenshot(page, prefijo: str) -> None:
    """Guarda captura de pantalla en storage/logs/scraper_debug para depuración."""
    try:
        DEBUG_DIR.mkdir(parents=True, exist_ok=True)
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        path = DEBUG_DIR / f"walmart_debug_{prefijo}_{ts}.png"
        page.screenshot(path=str(path))
        print(f"[*] Debug: captura guardada en storage/logs/scraper_debug/{path.name}", file=sys.stderr)
    except Exception as e:
        print(f"[WARN] No se pudo guardar captura de debug: {e}", file=sys.stderr)


def main():
    url = (os.environ.get("WALMART_PRECIOS_BAJOS_URL") or URL_PRECIOS_BAJOS).strip()
    if not url.startswith("http"):
        url = URL_PRECIOS_BAJOS

    if not os.environ.get("DB_HOST"):
        print("[INFO] DB_HOST no definido en .env. Configura DB_* para guardar en MySQL.", file=sys.stderr)

    headless = os.environ.get("HEADLESS", "1") == "1"
    # Solo en Linux sin DISPLAY (ej. VPS) forzamos headless; en Windows DISPLAY no existe y debe abrir ventana
    if sys.platform != "win32" and not headless and not os.environ.get("DISPLAY"):
        headless = True
        if os.environ.get("HEADLESS") == "0":
            print("[*] VPS sin DISPLAY: no se puede abrir navegador visible. Ejecutando en headless.", file=sys.stderr)
            print("[*] Para intentar con captcha usa: HEADLESS=0 xvfb-run ./python/venv/bin/python -u python/scraper_walmart.py", file=sys.stderr)
        else:
            print("[*] VPS sin DISPLAY; usando headless. Para captcha: HEADLESS=0 xvfb-run ... scraper_walmart.py", file=sys.stderr)

    print(f"[*] Scraper Walmart México - Precios Bajos: {url[:70]}...")
    if not headless:
        print("[*] Modo NO HEADLESS: se abrirá la ventana del navegador para que puedas resolver el captcha si aparece.")
    # Stealth + contexto persistente: perfil en disco (caché/cookies) para parecer navegador real
    WALMART_PROFILE_DIR.mkdir(parents=True, exist_ok=True)
    productos_por_sku: dict[str, dict] = {}

    with sync_playwright() as p:
        # Contexto persistente: misma carpeta de usuario entre ejecuciones (caché, cookies, más "humano")
        context = p.chromium.launch_persistent_context(
            str(WALMART_PROFILE_DIR),
            headless=headless,
            viewport={"width": 1920, "height": 1080},
            user_agent=random.choice(USER_AGENTS),
            args=["--disable-blink-features=AutomationControlled"],
            ignore_default_args=["--enable-automation"],
        )
        # Si hay sesión guardada en el panel (cookies_json o session_data), inyectarla
        row = _get_walmart_session_from_db()
        cookies = _get_cookies_from_row(row)
        if cookies:
            context.add_cookies(cookies)
            print("[*] Sesión Walmart cargada desde el panel (cookies).")

        page = context.new_page()
        playwright_stealth.Stealth().apply_stealth_sync(page)

        try:
            page.goto(url, wait_until="domcontentloaded", timeout=45000)
            time.sleep(random.uniform(3, 6))

            # Walmart muestra "Verifica tu identidad" (captcha "Mantén presionado")
            if _tiene_captcha_walmart(page):
                if headless:
                    _guardar_debug_screenshot(page, "captcha")
                    print("[WARN] Walmart mostró la página 'Verifica tu identidad' (protección anti-bot).", file=sys.stderr)
                    print("[*] Ejecuta en modo NO HEADLESS para ver la ventana: HEADLESS=0 python3 scraper_walmart.py", file=sys.stderr)
                    lista = []
                else:
                    # Modo NO HEADLESS: beep + auto-detección cuando la página de productos esté visible
                    _beep_captcha()
                    print()
                    print(">>> ¡RESUELVE EL CAPTCHA MANUALMENTE EN LA VENTANA DEL NAVEGADOR! <<<")
                    print(">>> Mantén presionado el botón hasta que cargue la página de ofertas. <<<")
                    print(">>> El script revisa cada 2 s si ya cargó; no hace falta pulsar Enter. <<<")
                    print()
                    if _esperar_pagina_productos_auto(page, intervalo=2.0, tiempo_max=600.0):
                        print("[*] Página de productos detectada. Continuando...")
                        time.sleep(random.uniform(2, 4))
                        _scroll_para_lazy_load(page)
                        time.sleep(2)
                        lista = extraer_productos(page)
                        _guardar_sesion_walmart_en_bd(context.cookies())
                    else:
                        print("[WARN] No se detectó la página de productos tras 10 min.", file=sys.stderr)
                        lista = []
            else:
                _scroll_para_lazy_load(page)
                time.sleep(random.uniform(2, 4))
                try:
                    page.wait_for_selector("a[href*='/ip/']", timeout=25000)
                except Exception:
                    print("[*] No se detectaron enlaces /ip/ aún; scroll adicional...", file=sys.stderr)
                    _scroll_para_lazy_load(page)
                    time.sleep(random.uniform(3, 5))
                lista = extraer_productos(page)
                if lista:
                    _guardar_sesion_walmart_en_bd(context.cookies())
            if not lista:
                _guardar_debug_screenshot(page, "sin_productos")
        except Exception as e:
            print(f"[WARN] Error al cargar página: {e}", file=sys.stderr)
            lista = []
        finally:
            context.close()

    for p in lista:
        sku = _sku_de_producto(p)
        if sku:
            productos_por_sku[sku] = p

    productos = list(productos_por_sku.values())
    if not productos:
        print("[WARN] No se encontraron productos.", file=sys.stderr)
        print("[*] Revisa storage/logs/scraper_debug/walmart_debug_*.png para ver captcha o estado de la página.", file=sys.stderr)
        print("[*] En VPS: Walmart suele pedir captcha; para resolverlo hay que ejecutar desde un PC con pantalla (HEADLESS=0) o conectar por VNC al display de xvfb.", file=sys.stderr)
        print("[*] Otra URL: WALMART_PRECIOS_BAJOS_URL=https://www.walmart.com.mx/browse/... en .env", file=sys.stderr)
        return

    print(f"[*] Productos únicos por SKU: {len(productos)}")
    if os.environ.get("DB_HOST"):
        guardar_en_mysql(productos)
    else:
        print("[SKIP] No se guardó en DB (configura DB_* en .env).")


if __name__ == "__main__":
    main()
