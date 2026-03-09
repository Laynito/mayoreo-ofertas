#!/usr/bin/env python3
"""
Scraper Mercado Libre México - Fase 1 Motor Python
Usa mysql-connector-python. Lee .env de Laravel (incl. contraseña con #).
Selectores para listado ML México en vivo (ofertas generales + relámpago).
"""

import json
import os
import random
import re
import sys
import time
from pathlib import Path

# Cargar .env de Laravel (directorio raíz del proyecto) — respeta comillas para contraseñas con #
PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"

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
        # Quitar comillas envolventes (para que DB_PASSWORD con #, $, ! se lea bien)
        if (v.startswith("'") and v.endswith("'")) or (v.startswith('"') and v.endswith('"')):
            v = v[1:-1]
        os.environ[k] = v

_cargar_env()

import mysql.connector
from playwright.sync_api import sync_playwright
import playwright_stealth


# --- Selectores: genéricos ML (mesh-card, ui-search-*, andes-card) + fallback poly-card (HTML local) ---
CARD_SELECTOR = "[data-testid='mesh-card'], .ui-search-result, .ui-search-layout__item, ol.ui-search-layout li, .andes-card, .poly-card, [class*='ui-search-layout'] > li"
# Fallback: contenedores con enlace a producto (absoluto o relativo /p/MLM)
CARD_SELECTOR_LIVE = "li:has(a[href*='MLM']), article:has(a[href*='MLM']), div:has(a[href*='MLM']), li:has(a[href*='/p/']), article:has(a[href*='/p/']), [class*='ResultsGrid'] li"
# Para saber que la página de ofertas cargó (enlace a producto: absoluto o relativo /p/MLM...)
PRODUCT_LINK_SELECTOR = "a[href*='mercadolibre.com.mx'][href*='MLM'], a[href*='articulo.mercadolibre.com.mx'], a[href*='/p/MLM'], a[href*='MLM']"
# Enlace y nombre (listado en vivo usa a con href a /p/MLM... o articulo; HTML local poly-component)
SELECTOR_LINK = "a.poly-component__title, a[href*='mercadolibre'][href*='MLM'], a[href*='/p/MLM'], a[href*='MLM'], a[href*='mercadolibre'], a[href*='/p/']"
SELECTOR_IMAGEN = "img.poly-component__picture, img[data-src], img[src*='http'], img"
SELECTOR_NOMBRE = "a.poly-component__title, .poly-component__title, h2, [class*='title'], [class*='ItemTitle']"
# Precio actual: bloque con fraction + cents (poly-price__current)
SELECTOR_PRECIO = "div.poly-price__current .andes-money-amount__fraction, div.poly-price__current .andes-money-amount, span.andes-money-amount__fraction, .andes-money-amount"
# Precio original: <s> tachado (listado y PDP). Incluye oferta relámpago: ui-pdp-price__original-value, andes-money-amount--previous, y aria-label "Antes: X pesos"
SELECTOR_PRECIO_ORIGINAL = (
    "s.ui-pdp-price__original-value.andes-money-amount--previous, "
    "s.andes-money-amount--previous, s.andes-money-amount, .andes-money-amount--previous, "
    "s.ui-pdp-price__original-value, [aria-label*='Antes:']"
)
SELECTOR_DESCUENTO_PILL = "span.andes-money-amount__discount, .poly-price__disc--pill"

URL_BUSQUEDA = os.environ.get("ML_SEARCH_URL", "https://listado.mercadolibre.com.mx/ofertas")
# URL alternativa si la principal de ofertas falla (p. ej. login/captcha)
URL_OFERTAS_ALTERNATIVA = "https://www.mercadolibre.com.mx/ofertas#nav-header"
# Secciones de ofertas ML. Usamos www (mismo dominio que homepage) para no disparar muro de login de listado.
OFFER_URLS = [
    "https://www.mercadolibre.com.mx/ofertas",  # Ofertas generales (www = misma sesión que _pasar_filtro_ml)
    "https://www.mercadolibre.com.mx/ofertas?container_id=MLM779363-1&promotion_type=lightning#filter_applied=promotion_type&filter_position=2&is_recommended_domain=false&origin=scut",  # Ofertas relámpago
]
TIENDA = "Mercado Libre"
# User-Agent real: Chrome actualizado para reducir bloqueos (ML detecta bots por UA antiguo)
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
]


# Bases para convertir URLs relativas (HTML guardado o listado ML)
BASE_ML_SITE = "https://www.mercadolibre.com.mx"
BASE_ML_IMAGES = "https://http2.mlstatic.com"


def _url_absoluta(url: str | None) -> str | None:
    """Asegura URL absoluta: si empieza con //, añade https: (para Telegram y DB)."""
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        return "https:" + u
    return u


def _url_producto_absoluta(url: str | None) -> str | None:
    """URL del producto siempre absoluta: // -> https:, /path -> BASE_ML_SITE + path."""
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        return "https:" + u
    if u.startswith("/"):
        return BASE_ML_SITE + u
    if u.startswith("./"):
        return BASE_ML_SITE + u[1:]
    return u if u.startswith("http://") or u.startswith("https://") else (BASE_ML_SITE + "/" + u)


def _url_imagen_absoluta(url: str | None) -> str | None:
    """URL de imagen siempre absoluta. Si es relativa (./ o /), usa BASE_ML_IMAGES + nombre de archivo."""
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        return "https:" + u
    if u.startswith("http://") or u.startswith("https://"):
        return u
    # Relativa: ./carpeta/D_xxx.webp -> https://http2.mlstatic.com/D_xxx.webp
    if u.startswith("./"):
        u = u[2:]
    if u.startswith("/"):
        u = u[1:]
    filename = u.split("/")[-1] if "/" in u else u
    return f"{BASE_ML_IMAGES}/{filename}" if filename else None


def _extraer_primera_url_srcset(srcset: str | None) -> str | None:
    """De srcset tipo 'url1 2x, url2 1x' devuelve la primera URL (absoluta)."""
    if not srcset or not srcset.strip():
        return None
    part = srcset.strip().split(",")[0].strip()
    if not part:
        return None
    # "https://http2.mlstatic.com/D_2X_xxx.webp 2x" -> URL
    idx = part.rfind(" ")
    if idx > 0 and part[idx:].strip() and not part[:idx].strip().endswith("/"):
        return part[:idx].strip()
    return part


def parse_precio(texto: str) -> float | None:
    """Extrae número decimal de un string de precio (ej: '$ 12,345.67' o '12.345,67')."""
    if not texto:
        return None
    # Quitar símbolos y espacios, normalizar decimal
    s = re.sub(r"[^\d,.\s]", "", texto.strip())
    s = s.replace(" ", "")
    if "," in s and "." in s:
        # Uno es miles, otro decimal: el último es decimal
        if s.rfind(".") > s.rfind(","):
            s = s.replace(",", "")
        else:
            s = s.replace(".", "").replace(",", ".")
    elif "," in s:
        # Solo coma: asumir decimal o miles según cantidad
        if s.count(",") == 1 and len(s.split(",")[-1]) <= 2:
            s = s.replace(",", ".")
        else:
            s = s.replace(",", "")
    try:
        return float(s)
    except ValueError:
        return None


def scroll_para_lazy_load(page, pasos: int = 8):
    """Hace scroll con esperas aleatorias (stealth) para que ML cargue las imágenes (lazy load)."""
    for i in range(pasos):
        page.evaluate("window.scrollBy(0, window.innerHeight * 0.6)")
        page.wait_for_timeout(random.randint(400, 900))
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(random.randint(1500, 3500))
    page.evaluate("window.scrollTo(0, 0)")
    page.wait_for_timeout(random.randint(300, 700))


def _query_one(card, selectors: str):
    """Primer elemento que coincida con alguno de los selectores (separados por coma)."""
    for sel in (s.strip() for s in selectors.split(",")):
        el = card.query_selector(sel)
        if el:
            return el
    return None


def _precio_desde_aria_label(el) -> float | None:
    """Extrae precio desde aria-label tipo 'Antes: 634 pesos con 30 centavos'."""
    if not el:
        return None
    label = (el.get_attribute("aria-label") or "").strip()
    if not label or "Antes:" not in label:
        return None
    # "Antes: 634 pesos con 30 centavos" -> 634.30
    m = re.search(r"Antes:\s*(\d+)\s*pesos(?:\s*con\s*(\d+)\s*centavos)?", label, re.I)
    if not m:
        return None
    enteros = int(m.group(1))
    centavos = int(m.group(2)) if m.group(2) else 0
    return round(enteros + centavos / 100.0, 2)


def _precio_actual_desde_card(card) -> float:
    """Precio actual desde div.poly-price__current (fraction + cents)."""
    bloque = card.query_selector("div.poly-price__current")
    if not bloque:
        price_el = _query_one(card, SELECTOR_PRECIO)
        return parse_precio(price_el.inner_text()) if price_el else 0.0
    frac = bloque.query_selector(".andes-money-amount__fraction")
    cents = bloque.query_selector(".andes-money-amount__cents")
    entero = parse_precio(frac.inner_text()) if frac else 0.0
    decimal = parse_precio(cents.inner_text()) if cents else 0.0
    if decimal and decimal < 1:
        return round(entero + decimal, 2)
    if decimal and decimal >= 1:
        return round(entero + decimal / 100.0, 2)
    return round(entero, 2)


def _descuento_desde_pill(card) -> int | None:
    """Ej: '30% OFF' -> 30. None si no hay pill."""
    el = _query_one(card, SELECTOR_DESCUENTO_PILL)
    if not el:
        return None
    txt = (el.inner_text() or "").strip()
    m = re.search(r"(\d+)\s*%\s*OFF", txt, re.I)
    return int(m.group(1)) if m else None


def extraer_productos(page, card_selector: str | None = None) -> list[dict]:
    """Extrae productos usando selectores de tarjetas (por defecto CARD_SELECTOR)."""
    cards = page.query_selector_all(card_selector or CARD_SELECTOR)
    productos = []
    for card in cards:
        try:
            link_el = _query_one(card, SELECTOR_LINK)
            url_producto_raw = link_el.get_attribute("href") if link_el else ""
            if not url_producto_raw:
                continue
            url_producto = _url_producto_absoluta(url_producto_raw) or url_producto_raw
            sku_match = re.search(r"(MLM|MLA|MLB)\d+", url_producto)
            sku = sku_match.group(0).replace("-", "") if sku_match else str(abs(hash(url_producto)))[:16]

            img_el = _query_one(card, SELECTOR_IMAGEN)
            url_imagen = None
            if img_el:
                # Preferir srcset (suele tener URL absoluta ML), luego data-src, luego src
                raw = (
                    _extraer_primera_url_srcset(img_el.get_attribute("srcset"))
                    or img_el.get_attribute("data-src")
                    or img_el.get_attribute("src")
                )
                url_imagen = _url_imagen_absoluta(raw) if raw else None

            name_el = _query_one(card, SELECTOR_NOMBRE)
            nombre = (name_el.inner_text() or "").strip() if name_el else ""

            precio_actual = _precio_actual_desde_card(card)
            if not precio_actual:
                price_el = _query_one(card, SELECTOR_PRECIO)
                precio_actual = parse_precio(price_el.inner_text()) if price_el else 0.0

            old_el = _query_one(card, SELECTOR_PRECIO_ORIGINAL)
            precio_original = None
            if old_el:
                # Preferir aria-label (fiable en oferta relámpago): "Antes: 634 pesos con 30 centavos"
                precio_original = _precio_desde_aria_label(old_el)
                if precio_original is None:
                    precio_original = parse_precio(old_el.inner_text())

            descuento = _descuento_desde_pill(card)
            if descuento is None and precio_original and precio_original > 0 and precio_actual < precio_original:
                descuento = int(round((1 - precio_actual / precio_original) * 100))
            if descuento is None:
                descuento = 0
            # Oferta relámpago: a veces la tarjeta no tiene <s> con precio anterior pero sí el % OFF
            if precio_original is None and descuento and descuento > 0 and precio_actual and precio_actual > 0:
                precio_original = round(precio_actual / (1 - descuento / 100.0), 2)

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


def _get_db_connection():
    """Conexión MySQL con variables de .env."""
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


def _get_mercado_libre_from_db() -> dict | None:
    """
    Consulta la tabla marketplaces por slug='mercado_libre'.
    Devuelve {'es_activo': bool, 'url_busqueda': str|None, 'configuracion': dict|None} o None si no hay fila/error.
    configuracion puede contener 'urls' (lista de URLs de secciones: Relámpago, Liquidación, etc.).
    """
    try:
        conn = _get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT es_activo, url_busqueda, configuracion FROM marketplaces WHERE slug = %s LIMIT 1",
            ("mercado_libre",),
        )
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        if row and row.get("configuracion") is not None and isinstance(row["configuracion"], str):
            try:
                row["configuracion"] = json.loads(row["configuracion"])
            except (TypeError, ValueError):
                row["configuracion"] = {}
        return row
    except Exception as e:
        print(f"[WARN] No se pudo leer marketplaces (¿tabla existe?): {e}", file=sys.stderr)
        return None


def guardar_en_mysql(productos: list[dict]) -> None:
    """Inserta o actualiza productos en MySQL con mysql-connector-python (ON DUPLICATE KEY UPDATE por SKU)."""
    conn = _get_db_connection()
    cursor = conn.cursor()

    sql = """
    INSERT INTO productos (nombre, sku, precio_actual, precio_original, descuento, url_producto, url_imagen, tienda, created_at, updated_at)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        precio_actual = VALUES(precio_actual),
        precio_original = VALUES(precio_original),
        descuento = VALUES(descuento),
        url_imagen = VALUES(url_imagen),
        updated_at = NOW()
    """
    for p in productos:
        cursor.execute(sql, (
            p["nombre"],
            p["sku"],
            p["precio_actual"],
            p["precio_original"],
            p["descuento"],
            p["url_producto"],
            p["url_imagen"],
            p["tienda"],
        ))
    conn.commit()
    cursor.close()
    conn.close()
    print(f"[OK] Guardados/actualizados {len(productos)} productos en MySQL.")


def _sku_de_producto(p: dict) -> str | None:
    """Obtiene SKU único para deduplicar (evitar mismo producto en varias secciones)."""
    sku = (p.get("sku") or "").strip()
    if sku:
        return sku
    url = (p.get("url") or "").strip()
    if url:
        # ML: .../MLM123456_123 -> MLM123456_123
        return url.split("/")[-1].split("?")[0] or None
    return None


def _aceptar_cookies(page, timeout_ms: int = 5000) -> None:
    """Si aparece el banner de cookies de ML, hace clic en 'Aceptar cookies'."""
    for selector in [
        "button:has-text('Aceptar cookies')",
        "a:has-text('Aceptar cookies')",
        "[data-testid='action-button']:has-text('Aceptar')",
        "button:has-text('Aceptar')",
    ]:
        try:
            btn = page.locator(selector).first
            if btn.is_visible(timeout=2000):
                btn.click(timeout=timeout_ms)
                time.sleep(random.uniform(1, 2))
                return
        except Exception:
            continue


def _tiene_muro_login(page) -> bool:
    """Detecta si ML mostró el muro 'Para continuar, ingresa a tu cuenta'."""
    try:
        return page.get_by_text("ingresa a tu cuenta", exact=False).first.is_visible(timeout=2000)
    except Exception:
        return False


# Enlace de la barra de navegación para entrar a Ofertas (simula clic de usuario)
NAV_OFERTAS_SELECTOR = "a.nav-menu-item-link[href*='ofertas'], a[href*='/ofertas#nav-header']"

def _pasar_filtro_ml(page) -> None:
    """
    Pasa el filtro de ML: visita la homepage, acepta cookies y entra a Ofertas
    haciendo clic en el enlace del menú (como un usuario) para mantener sesión.
    """
    homepage = "https://www.mercadolibre.com.mx"
    print("[*] Inicializando sesión en ML (homepage + cookies)...")
    try:
        page.goto(homepage, wait_until="load", timeout=30000)
        time.sleep(random.uniform(3, 6))
        _aceptar_cookies(page, timeout_ms=8000)
        time.sleep(random.uniform(2, 4))
        if _tiene_muro_login(page):
            time.sleep(random.uniform(2, 4))
            _aceptar_cookies(page, timeout_ms=5000)
        if not _tiene_muro_login(page):
            try:
                link_ofertas = page.locator(NAV_OFERTAS_SELECTOR).first
                link_ofertas.click(timeout=8000)
                time.sleep(random.uniform(3, 6))
                _aceptar_cookies(page, timeout_ms=5000)
                time.sleep(random.uniform(1, 2))
            except Exception:
                page.goto("https://www.mercadolibre.com.mx/ofertas", wait_until="load", timeout=30000)
                time.sleep(random.uniform(2, 4))
                _aceptar_cookies(page, timeout_ms=5000)
    except Exception as e:
        print(f"[WARN] Inicio de sesión ML: {e}", file=sys.stderr)


def _scrape_una_url(page, url: str, card_selector: str, timeout_selector: int, screenshot_path) -> list[dict]:
    """Abre una URL, espera listado, hace scroll y devuelve lista de productos. [] si falla."""
    is_live = url.startswith("http://") or url.startswith("https://")
    if is_live:
        time.sleep(random.uniform(1, 3))
    page.goto(url, wait_until="load", timeout=45000)
    if is_live:
        time.sleep(random.uniform(2, 4))
        _aceptar_cookies(page, timeout_ms=8000)
        time.sleep(random.uniform(1, 3))
        if _tiene_muro_login(page):
            _pasar_filtro_ml(page)
            time.sleep(random.uniform(1, 2))
            page.goto(url, wait_until="load", timeout=45000)
            time.sleep(random.uniform(2, 4))
            _aceptar_cookies(page, timeout_ms=8000)
            time.sleep(random.uniform(2, 5))
        else:
            time.sleep(random.uniform(1, 3))
    try:
        page.wait_for_selector(PRODUCT_LINK_SELECTOR, timeout=timeout_selector)
    except Exception:
        return []
    try:
        page.wait_for_selector(card_selector, timeout=timeout_selector)
    except Exception:
        try:
            page.wait_for_selector(CARD_SELECTOR_LIVE, timeout=timeout_selector)
            card_selector = CARD_SELECTOR_LIVE
        except Exception:
            return []
    scroll_para_lazy_load(page)
    if is_live:
        time.sleep(random.uniform(2, 5))
    return extraer_productos(page, card_selector=card_selector)


def main():
    # Cerebro central: si existe marketplace mercado_libre en BD, respetar es_activo y URLs (configuracion.urls o url_busqueda)
    ml_row = _get_mercado_libre_from_db()
    urls_to_scrape = []
    if ml_row is not None:
        if not ml_row.get("es_activo", True):
            print("[*] Marketplace Mercado Libre está desactivado en el panel. No se ejecuta el scraper.")
            sys.exit(0)
        config = ml_row.get("configuracion") or {}
        urls_config = config.get("urls")
        if urls_config and isinstance(urls_config, list):
            urls_to_scrape = [u.strip() for u in urls_config if u and str(u).strip().startswith("https://")]
            if urls_to_scrape:
                print(f"[*] Usando {len(urls_to_scrape)} URLs de secciones desde panel (configuracion.urls).")
        if not urls_to_scrape:
            url_busqueda = (ml_row.get("url_busqueda") or "").strip()
            if url_busqueda:
                urls_to_scrape = [url_busqueda]
                print(f"[*] Usando URL de ofertas desde panel: {url_busqueda[:60]}...")
            else:
                urls_to_scrape = OFFER_URLS
    if not urls_to_scrape:
        urls_to_scrape = OFFER_URLS

    if not os.environ.get("DB_HOST"):
        print("[INFO] DB_HOST no definido en .env. Configura DB_* y usa MySQL.", file=sys.stderr)
    screenshot_path = PROJECT_ROOT / "error.png"
    TIMEOUT_SELECTOR = 60000
    card_selector = CARD_SELECTOR

    print(f"[*] Scrapeando {len(urls_to_scrape)} secciones en vivo (deduplicado por SKU).")

    productos_por_sku: dict[str, dict] = {}
    total_bruto = 0

    # En servidor sin X (sin DISPLAY), forzar headless para no fallar
    headless = os.environ.get("HEADLESS", "1") == "1"
    if not headless and not os.environ.get("DISPLAY"):
        headless = True
        print("[*] Sin DISPLAY en el servidor; usando modo headless (HEADLESS=0 requiere escritorio o xvfb-run).", file=sys.stderr)
    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=headless,
            args=["--disable-blink-features=AutomationControlled"],
        )
        user_agent = random.choice(USER_AGENTS)
        # Viewport humano: tamaño común 1920x1080 para reducir detección de bot
        context = browser.new_context(
            user_agent=user_agent,
            viewport={"width": 1920, "height": 1080},
        )
        page = context.new_page()
        playwright_stealth.Stealth().apply_stealth_sync(page)
        _pasar_filtro_ml(page)

        total_secciones = len(urls_to_scrape)
        print(f"[*] [  0% ] Iniciando scraper ({total_secciones} sección/es)...")
        for i, url in enumerate(urls_to_scrape):
            pct_inicio = int((i / total_secciones) * 100)
            label = url if len(url) < 60 else url[:57] + "..."
            print(f"[*] [{pct_inicio:3d}% ] [{i+1}/{total_secciones}] {label}")
            try:
                lista = _scrape_una_url(page, url, card_selector, TIMEOUT_SELECTOR, screenshot_path)
            except Exception as e:
                print(f"[WARN] Error en esta URL: {e}", file=sys.stderr)
                lista = []
            total_bruto += len(lista)
            for p in lista:
                sku = _sku_de_producto(p)
                if sku:
                    productos_por_sku[sku] = p
            pct_fin = int(((i + 1) / total_secciones) * 100)
            if not lista:
                debug_png = PROJECT_ROOT / f"debug_seccion_{i+1}.png"
                try:
                    page.screenshot(path=str(debug_png))
                    print(f"[*] [{pct_fin:3d}% ] Sin productos. Captura: {debug_png.name}")
                except Exception:
                    print(f"[*] [{pct_fin:3d}% ] Sin productos en esta sección.")
            else:
                print(f"[*] [{pct_fin:3d}% ] +{len(lista)} productos (únicos total: {len(productos_por_sku)})")
            # Retraso aleatorio entre secciones para no parecer ataque automatizado
            if i < total_secciones - 1:
                delay = random.uniform(5, 10)
                print(f"[*] [{pct_fin:3d}% ] Pausa {delay:.1f}s antes de la siguiente sección...")
                time.sleep(delay)
        print(f"[*] [100% ] Scrapeo terminado. Procesando productos únicos...")

        context.close()
        browser.close()

    productos = list(productos_por_sku.values()) if productos_por_sku else []
    if not productos:
        print("[WARN] No se encontraron productos en ninguna sección.", file=sys.stderr)
        print("[*] Revisa debug_seccion_*.png para ver si ML muestra captcha o bloqueo.", file=sys.stderr)
        print("[*] Prueba con HEADLESS=0 para abrir el navegador visible: HEADLESS=0 python scraper_ml.py", file=sys.stderr)
        return

    # Reporte de limpieza: cuánta basura se le ahorró a la base y a Telegram
    unicos = len(productos)
    duplicados_evitados = total_bruto - unicos
    print()
    print("[*] Total de productos encontrados en bruto:", total_bruto)
    print("[*] Productos únicos filtrados por SKU:", unicos)
    if duplicados_evitados > 0:
        print(f"[Refuerzo] Se evitaron {duplicados_evitados} duplicados.")
    if os.environ.get("DB_HOST"):
        print("[*] [100% ] Guardando en base de datos...")
        guardar_en_mysql(productos)
    else:
        print("[SKIP] No se guardó en DB (falta config MySQL en .env).")


if __name__ == "__main__":
    main()
