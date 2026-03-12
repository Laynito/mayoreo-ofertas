#!/usr/bin/env python3
"""
Scraper Elektra México (tienda VTEX).
Extrae ofertas en vivo desde páginas como /liquidacion usando Playwright.
Imágenes: img[src*="elektra.vtexassets.com"] (clase tipo elektra-elektra-components-*).
Guarda en tabla productos con tienda='Elektra'.
"""

import hashlib
import json
import os
import random
import re
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"
DEBUG_DIR = PROJECT_ROOT / "storage" / "logs" / "scraper_debug"


def _cargar_env():
    if not ENV_PATH.exists():
        return
    try:
        from dotenv import dotenv_values
        _env = dotenv_values(ENV_PATH)
        for k, v in (_env or {}).items():
            if k in os.environ or v is None:
                continue
            v = str(v).strip()
            if (v.startswith("'") and v.endswith("'")) or (v.startswith('"') and v.endswith('"')):
                v = v[1:-1]
            os.environ[k] = v
    except Exception:
        pass


_cargar_env()

import mysql.connector
from playwright.sync_api import sync_playwright
import playwright_stealth

# --- Config ---
TIENDA = "Elektra"
BASE_ELEKTRA = "https://www.elektra.mx"
# URL por defecto: liquidación (configurable por BD → marketplaces.configuracion.urls o url_busqueda)
URL_LIQUIDACION = os.environ.get("ELEKTRA_URL", "https://www.elektra.mx/liquidacion")
# Máximo de páginas por URL (paginación) para no hacer loops infinitos
MAX_PAGINAS_POR_URL = int(os.environ.get("ELEKTRA_MAX_PAGINAS", "30"))

# Selectores en vivo: VTEX / Elektra (clases con hash pueden cambiar; usamos patrones).
# Imagen de producto: clase tipo elektra-elektra-components-1Par_6Feo2bWXgLl_wEzoq, src vtexassets
CARD_SELECTOR = (
    "a[href*='elektra.mx'][href*='/p/']:has(img[src*='vtexassets.com']), "
    "[class*='elektra-elektra-components']:has(a[href*='/p/']):has(img[src*='vtexassets'])"
)
# Si la tarjeta es el enlace que envuelve imagen + texto
LINK_IN_CARD = "a[href*='elektra.mx'][href*='/p/']"
SELECTOR_IMAGEN = "img[src*='vtexassets.com'], img[class*='elektra-elektra-components']"
SELECTOR_NOMBRE = (
    "[class*='productName'], [class*='product-name'], [class*='title'], "
    "h2, h3, [class*='nameContainer'] span, a[href*='/p/'] span"
)
SELECTOR_PRECIO = (
    "[class*='sellingPrice'], [class*='selling-price'], [class*='priceContainer'] span, "
    "[class*='price']:not([class*='listPrice']):not(s)"
)
SELECTOR_PRECIO_ORIGINAL = "s, [class*='listPrice'], [class*='list-price'], [class*='original']"
SELECTOR_DESCUENTO = "[class*='discount'], [class*='discountPercentage'], [class*='badge']"

USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
]


def _url_producto_absoluta(url: str | None) -> str | None:
    if not url or not url.strip():
        return url
    u = url.strip()
    if u.startswith("//"):
        u = "https:" + u
    if u.startswith("/"):
        u = BASE_ELEKTRA + u
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
        return BASE_ELEKTRA + u
    return BASE_ELEKTRA + "/" + u


def _extraer_primera_url_srcset(srcset: str | None) -> str | None:
    if not srcset or not srcset.strip():
        return None
    part = srcset.strip().split(",")[0].strip()
    if not part:
        return None
    idx = part.rfind(" ")
    if idx > 0:
        return part[:idx].strip()
    return part


def parse_precio(texto: str) -> float | None:
    if not texto:
        return None
    s = re.sub(r"[^\d,.\s]", "", texto.strip()).replace(" ", "")
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


def _sku_desde_url(url: str) -> str:
    if not (url or "").strip():
        return hashlib.md5(b"").hexdigest()[:16]
    parsed = urlparse(url)
    path = (parsed.path or "").rstrip("/")
    url_normalizada = f"{parsed.scheme or 'https'}://{parsed.netloc or ''}{path}"
    # Elektra/VTEX: /p/nombre-producto-123 o /nombre-producto/p
    if "/p/" in path:
        segments = [s for s in path.split("/") if s]
        if segments:
            last = segments[-1]
            if last.isdigit():
                return "ekt_" + last
            return hashlib.md5(url_normalizada.encode("utf-8")).hexdigest()[:16]
    return hashlib.md5(url_normalizada.encode("utf-8")).hexdigest()[:16]


def _query_one(container, selectors: str):
    for sel in (s.strip() for s in selectors.split(",")):
        try:
            el = container.query_selector(sel)
            if el:
                return el
        except Exception:
            continue
    return None


def _query_all(container, selectors: str):
    for sel in (s.strip() for s in selectors.split(",")):
        try:
            els = container.query_selector_all(sel)
            if els:
                return els
        except Exception:
            continue
    return []


def scroll_para_lazy_load(page, pasos: int = 6):
    for i in range(pasos):
        page.evaluate("window.scrollBy(0, window.innerHeight * 0.6)")
        page.wait_for_timeout(random.randint(400, 900))
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(random.randint(1200, 2500))
    page.evaluate("window.scrollTo(0, 0)")
    page.wait_for_timeout(random.randint(300, 600))


def _es_url_producto(href: str) -> bool:
    """Filtra URLs que son de producto (no login, cart, categoría, etc.)."""
    if not href or "elektra.mx" not in href:
        return False
    path = (urlparse(href).path or "").strip("/").lower()
    if not path or path in ("login", "cart", "checkout", "ofertas", "liquidacion", "account"):
        return False
    # VTEX: producto suele ser /nombre-producto/p o /p/nombre o algo con más de un segmento
    segments = [s for s in path.split("/") if s]
    if len(segments) < 1:
        return False
    # Excluir páginas conocidas de categoría/landing
    if path.startswith("liquidacion") or path.startswith("ofertas"):
        return False
    return True


def _parece_url_producto(url: str) -> bool:
    """
    True si la URL parece ficha de producto en Elektra/VTEX.
    Un producto de Elektra termina en /p o tiene el formato -ID/p (ej. -1301061937/p).
    """
    if not url:
        return False
    url_lower = url.lower().strip()
    # Bloquear páginas que NO son productos
    bloqueados = [
        "/c/", "/checkout", "/login", "/account", "/cart",
        "buscador-de-tiendas", "?map=", "/marcas", "institucional", "contacto", "ayuda",
    ]
    if any(b in url_lower for b in bloqueados):
        return False
    # Producto VTEX: termina en /p o tiene formato -ID/p (ID de 7 a 15 dígitos)
    if url_lower.endswith("/p"):
        return True
    if re.search(r"-\d{7,15}/p", url_lower):
        return True
    return False


def extraer_productos(page, card_selector: str | None = None) -> list[dict]:
    """
    Extrae productos desde la página de listado Elektra (VTEX).
    Acepta enlaces relativos (/nombre-producto-123/p) y los normaliza a URL completa.
    """
    # --- Lógica de extracción: recoger todos los enlaces y filtrar por URL de producto ---
    elementos_enlace = page.query_selector_all("a[href]")
    enlaces_encontrados = []  # lista de (link_el, href_absoluto) sin duplicados

    for el in elementos_enlace:
        href = (el.get_attribute("href") or "").strip()
        if not href:
            continue
        # 1. Convertir link relativo a absoluto
        if href.startswith("/"):
            href = f"{BASE_ELEKTRA}{href}"
        # 2. Solo agregar si cumple el filtro de producto
        if not _parece_url_producto(href):
            continue
        href_norm = _url_producto_absoluta(href)
        if not href_norm:
            continue
        if href_norm in {h for _, h in enlaces_encontrados}:
            continue
        enlaces_encontrados.append((el, href_norm))

    print(f"      [INFO] Enlaces de productos detectados: {len(enlaces_encontrados)}")

    seen_urls = set()
    productos = []

    for link_el, url_producto in enlaces_encontrados:
        try:
            if url_producto in seen_urls:
                continue
            seen_urls.add(url_producto)
            # Imagen: dentro del enlace O dentro del mismo card (enlace e imagen son hermanos)
            img_el = link_el.query_selector("img[src*='vtexassets.com']")
            if not img_el:
                img_el = link_el.query_selector("img[class*='elektra-elektra-components']")
            if not img_el:
                try:
                    card_handle = link_el.evaluate_handle(
                        "el => el.closest('[class*=\"elektra-elektra-components\"]')"
                    )
                    card = card_handle.as_element() if card_handle else None
                except Exception:
                    card = None
                if card:
                    img_el = card.query_selector("img[src*='vtexassets.com']")
                    if not img_el:
                        img_el = card.query_selector("img[class*='elektra-elektra-components']")
            if not img_el:
                continue

            url_imagen = None
            raw = (
                _extraer_primera_url_srcset(img_el.get_attribute("srcset"))
                or img_el.get_attribute("data-src")
                or img_el.get_attribute("src")
            )
            url_imagen = _url_imagen_absoluta(raw) if raw else None

            # Card para nombre y precio (mismo que el de la imagen si lo buscamos por card)
            try:
                card_handle = link_el.evaluate_handle(
                    "el => el.closest('[class*=\"elektra-elektra-components\"]') || el"
                )
                card = card_handle.as_element() if card_handle else link_el
            except Exception:
                card = link_el

            # Nombre: texto del enlace o del card (evitar solo números/precio)
            nombre = (link_el.inner_text() or "").strip()
            if not nombre or len(nombre) < 2:
                name_el = _query_one(card, SELECTOR_NOMBRE)
                nombre = (name_el.inner_text() or "").strip() if name_el else "Sin nombre"
            lineas = [l.strip() for l in nombre.split("\n") if l.strip()]
            nombre_limpio = " ".join(l for l in lineas if l and not re.match(r"^\$?\s*[\d,.]+\s*$", l))
            if nombre_limpio:
                nombre = nombre_limpio[:255]
            if not nombre:
                nombre = "Sin nombre"

            # Precio: buscar en el card
            price_el = _query_one(card, SELECTOR_PRECIO)
            precio_actual = parse_precio(price_el.inner_text()) if price_el else 0.0
            if not precio_actual and card:
                for span in _query_all(card, "span"):
                    t = (span.inner_text() or "").strip()
                    p = parse_precio(t)
                    if p and 0.01 < p < 1_000_000:
                        precio_actual = p
                        break
            old_el = _query_one(card, SELECTOR_PRECIO_ORIGINAL)
            precio_original = parse_precio(old_el.inner_text()) if old_el else None
            desc_el = _query_one(card, SELECTOR_DESCUENTO)
            descuento = 0
            if desc_el:
                txt = (desc_el.inner_text() or "").strip()
                m = re.search(r"(\d+)\s*%", txt)
                if m:
                    descuento = int(m.group(1))
            if descuento == 0 and precio_original and precio_original > 0 and precio_actual and precio_actual < precio_original:
                descuento = int(round((1 - precio_actual / precio_original) * 100))

            if not precio_actual or precio_actual <= 0:
                continue

            productos.append({
                "nombre": nombre[:255],
                "sku": _sku_desde_url(url_producto),
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


def _slug_desde_nombre(nombre: str) -> str:
    """Convierte 'Línea Blanca' -> 'linea-blanca', 'Cuidado del Cabello' -> 'cuidado-del-cabello'."""
    if not nombre or not nombre.strip():
        return ""
    s = nombre.strip().lower()
    s = re.sub(r"[àáâãäåāăą]", "a", s)
    s = re.sub(r"[èéêëēė]", "e", s)
    s = re.sub(r"[ìíîïī]", "i", s)
    s = re.sub(r"[òóôõöō]", "o", s)
    s = re.sub(r"[ùúûüū]", "u", s)
    s = re.sub(r"[ñ]", "n", s)
    s = re.sub(r"[^a-z0-9\s-]", "", s)
    s = re.sub(r"[-\s]+", "-", s).strip("-")
    return s


def extraer_urls_categorias_desde_carrusel(page) -> list[tuple[str, str]]:
    """
    Extrae las URLs de categorías del carrusel (Celulares, Perfumes, etc.).
    Los ítems suelen ser div con img[alt] + h3, a veces sin <a>; si no hay enlace, se construye URL desde alt.
    """
    resultados: list[tuple[str, str]] = []
    seen: set[str] = set()
    img_selector = 'img[src*="vtexassets.com"][alt]'
    # Encontrar el contenedor del carrusel: varias imágenes con alt CORTO (nombre de categoría, no producto)
    IGNORAR_ALT = ("Scroll Left", "Scroll Right")
    MAX_ALT_CATEGORIA = 35  # "Cuidado del Cabello" tiene 20; productos tienen títulos largos
    carousel_container = None
    carousel_imgs: list = []
    for tag in ["nav", "div"]:
        if tag == "nav":
            candidates = page.query_selector_all("nav")
        else:
            candidates = page.query_selector_all('[class*="elektra-elektra-components"]')
        for el in candidates:
            imgs_inside = el.query_selector_all(img_selector)
            # Solo categorías: alt corto y no es flecha
            valid = [
                m for m in imgs_inside
                if (m.get_attribute("alt") or "").strip() not in IGNORAR_ALT
                and len((m.get_attribute("alt") or "").strip()) <= MAX_ALT_CATEGORIA
            ]
            if len(valid) >= 3 and len(valid) > len(carousel_imgs):
                carousel_container = el
                carousel_imgs = valid
        if carousel_imgs:
            break
    if not carousel_imgs:
        return resultados
    imgs = carousel_imgs
    for img in imgs:
        try:
            alt = (img.get_attribute("alt") or "").strip()
            if not alt or alt in ("Scroll Left", "Scroll Right"):
                continue
            url = None
            # 1) Intentar enlace ascendente
            a_handle = img.evaluate_handle('el => el.closest("a[href*=\'elektra.mx\']")')
            try:
                a_el = a_handle.as_element() if a_handle else None
            except Exception:
                a_el = None
            if a_el:
                href = a_el.get_attribute("href")
                if href:
                    url = _url_producto_absoluta(href) if href.startswith("/") else href
            # 2) Si no hay <a>, construir URL desde alt (Ej: Celulares -> /celulares)
            if not url or "elektra.mx" not in url:
                slug = _slug_desde_nombre(alt)
                if slug:
                    url = f"{BASE_ELEKTRA}/{slug}"
            if not url or url in seen:
                continue
            path = (urlparse(url).path or "").strip("/").lower()
            if path in ("login", "cart", "checkout", "account"):
                continue
            seen.add(url)
            resultados.append((url, alt))
        except Exception:
            continue
    return resultados


def obtener_siguiente_pagina(page) -> str | None:
    """
    Busca el enlace a la siguiente página (VTEX suele usar rel="next" o botón Siguiente).
    Devuelve la URL absoluta o None si no hay más páginas.
    """
    # rel="next" (estándar VTEX / SEO)
    next_el = page.query_selector('a[rel="next"]')
    if next_el:
        href = next_el.get_attribute("href")
        if href:
            return _url_producto_absoluta(href) if href.startswith("/") else (href if href.startswith("http") else BASE_ELEKTRA + href)
    # Enlaces con texto "Siguiente" o "Next" (VTEX Store Framework)
    for text in ("Siguiente", "Siguiente página", "Next", "›", "»"):
        try:
            loc = page.get_by_role("link", name=text)
            if loc.count() >= 1:
                href = loc.first.get_attribute("href")
                if href:
                    return href if href.startswith("http") else _url_producto_absoluta(href)
        except Exception:
            continue
    # Cualquier enlace en zona de paginación que apunte a la misma ruta con page distinto
    try:
        pagination_links = page.query_selector_all("[class*='pagination'] a[href*='elektra.mx'], [class*='pagination'] a[href*='liquidacion']")
        for a in pagination_links:
            href = (a.get_attribute("href") or "").strip()
            if not href or href == page.url:
                continue
            # Evitar "anterior" o página actual; si el href es distinto puede ser siguiente
            if "page=" in href or "from=" in href:
                return href if href.startswith("http") else _url_producto_absoluta(href)
    except Exception:
        pass
    return None


def _get_db_connection():
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    database = os.environ.get("DB_DATABASE", "mayoreo_cloud")
    user = os.environ.get("DB_USERNAME", "root")
    password = os.environ.get("DB_PASSWORD", "")
    if password and (str(password).startswith("'") or str(password).startswith('"')):
        password = str(password).strip("'\"").strip()
    return mysql.connector.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
    )


def _get_elektra_from_db() -> dict | None:
    try:
        conn = _get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT es_activo, url_busqueda, configuracion FROM marketplaces WHERE slug = %s LIMIT 1",
            ("elektra",),
        )
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        if row and row.get("configuracion") is not None:
            raw = row["configuracion"]
            if isinstance(raw, (bytes, bytearray)):
                raw = raw.decode("utf-8", errors="replace")
            if isinstance(raw, str):
                try:
                    row["configuracion"] = json.loads(raw)
                except (TypeError, ValueError):
                    row["configuracion"] = {}
        return row
    except Exception as e:
        print(f"[WARN] No se pudo leer marketplaces (elektra): {e}", file=sys.stderr)
        return None


def guardar_en_mysql(productos: list[dict]) -> None:
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
    print(f"[OK] Guardados/actualizados {len(productos)} productos Elektra en MySQL.")


def main():
    elektra_row = _get_elektra_from_db()
    urls_to_scrape = []
    if elektra_row is not None:
        if not elektra_row.get("es_activo", True):
            print("[*] Marketplace Elektra está desactivado en el panel. No se ejecuta el scraper.")
            sys.exit(0)
        config = elektra_row.get("configuracion") or {}
        urls_config = config.get("urls")
        if urls_config and isinstance(urls_config, list):
            urls_to_scrape = [u.strip() for u in urls_config if u and str(u).strip().startswith("https://")]
        if not urls_to_scrape:
            url_busqueda = (elektra_row.get("url_busqueda") or "").strip()
            if url_busqueda:
                urls_to_scrape = [url_busqueda]
        if not urls_to_scrape:
            urls_to_scrape = [URL_LIQUIDACION]
    else:
        urls_to_scrape = [URL_LIQUIDACION]

    DEBUG_DIR.mkdir(parents=True, exist_ok=True)
    headless = os.environ.get("HEADLESS", "1") == "1"
    if not headless and not os.environ.get("DISPLAY"):
        headless = True

    # Página donde está el carrusel de categorías (Celulares, Perfumes, etc.)
    url_para_carrusel = urls_to_scrape[0] if urls_to_scrape else URL_LIQUIDACION

    print(f"[*] Scrapeando Elektra en vivo.")
    productos_por_sku = {}
    total_bruto = 0

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=headless,
            args=["--disable-blink-features=AutomationControlled"],
        )
        context = browser.new_context(
            user_agent=random.choice(USER_AGENTS),
            viewport={"width": 1920, "height": 1080},
        )
        page = context.new_page()
        playwright_stealth.Stealth().apply_stealth_sync(page)

        # Cargar página y extraer categorías del carrusel (img alt="Celulares", "Perfumes", etc.)
        print(f"[*] Cargando página para detectar categorías: {url_para_carrusel[:55]}...")
        try:
            page.goto(url_para_carrusel, wait_until="domcontentloaded", timeout=45000)
            time.sleep(random.uniform(3, 6))
            try:
                page.wait_for_selector('img[src*="vtexassets.com"][alt]', timeout=15000)
            except Exception:
                pass
            time.sleep(random.uniform(1, 3))
            carousel = extraer_urls_categorias_desde_carrusel(page)
            if carousel:
                urls_to_scrape = [u for u, _ in carousel]
                nombres = [n for _, n in carousel]
                print(f"[*] Categorías del carrusel ({len(urls_to_scrape)}): {', '.join(nombres[:15])}{'...' if len(nombres) > 15 else ''}")
            else:
                print(f"[*] No se detectó carrusel; se usan URLs de configuración ({len(urls_to_scrape)}).")
        except Exception as e:
            print(f"[WARN] Error al cargar página para carrusel: {e}. Usando URLs de configuración.", file=sys.stderr)

        for i, url in enumerate(urls_to_scrape):
            current_url = url
            num_pagina = 0
            while current_url and num_pagina < MAX_PAGINAS_POR_URL:
                num_pagina += 1
                pag_label = f"pág {num_pagina}" if num_pagina > 1 else ""
                print(f"[*] [{i+1}/{len(urls_to_scrape)}] {current_url[:60]}... {pag_label}".strip())
                try:
                    page.goto(current_url, wait_until="domcontentloaded", timeout=45000)
                    time.sleep(random.uniform(3, 6))
                    try:
                        page.wait_for_selector("img[src*='vtexassets.com']", timeout=20000)
                    except Exception:
                        pass
                    time.sleep(random.uniform(2, 4))
                    # Scroll + espera para que VTEX/React renderice la galería de productos
                    page.evaluate("window.scrollBy(0, 800)")
                    time.sleep(3)
                    scroll_para_lazy_load(page)
                    time.sleep(random.uniform(2, 4))
                    # Debug: imprimir los primeros 15 enlaces que ve el scraper
                    try:
                        enlaces = page.query_selector_all("a[href]")
                        print(f"      [DEBUG CATEGORÍA] Analizando los primeros 15 enlaces de la página:")
                        for idx, a in enumerate(enlaces[:15]):
                            h = a.get_attribute("href") or ""
                            c = a.get_attribute("class") or ""
                            print(f"        -> {idx + 1}: {h[:75]}{'...' if len(h) > 75 else ''} | CLASE: {c[:50]}{'...' if len(c) > 50 else ''}")
                    except Exception as dbg:
                        print(f"      [DEBUG CATEGORÍA] Error al listar enlaces: {dbg}")
                    lista = extraer_productos(page)
                    if not lista:
                        n_links = len(page.query_selector_all("a[href*='elektra.mx']"))
                        n_imgs = len(page.query_selector_all("img[src*='vtexassets.com']"))
                        print(f"      [DEBUG] Enlaces elektra.mx: {n_links}, imágenes vtexassets: {n_imgs}")
                except Exception as e:
                    print(f"[WARN] Error en URL: {e}", file=sys.stderr)
                    lista = []
                total_bruto += len(lista)
                for p in lista:
                    sku = p.get("sku") or _sku_desde_url(p.get("url_producto", ""))
                    if sku:
                        productos_por_sku[sku] = p
                print(f"      +{len(lista)} productos (únicos total: {len(productos_por_sku)})")
                if not lista:
                    break
                # Siguiente página (solo en la primera URL de la categoría seguimos paginando)
                current_url = obtener_siguiente_pagina(page)
                if current_url:
                    time.sleep(random.uniform(2, 5))
                else:
                    break
            if i < len(urls_to_scrape) - 1:
                time.sleep(random.uniform(3, 6))

        context.close()
        browser.close()

    productos = list(productos_por_sku.values())
    if not productos:
        print("[WARN] No se encontraron productos.", file=sys.stderr)
        return

    if os.environ.get("DB_HOST"):
        guardar_en_mysql(productos)
    else:
        print("[SKIP] No se guardó en DB (config MySQL en .env).")


if __name__ == "__main__":
    main()
