#!/usr/bin/env python3
"""
Scraper Walmart México vía Sitemap oficial (sin navegador).
Lee https://www.walmart.com.mx/siteindex.xml → sitemaps de productos → URLs de productos.
Usa curl_cffi (impersonate Chrome TLS) + User-Agent iPhone; extrae nombre, precio y link; guarda en tabla productos.
Si detecta «Verifica tu identidad» se detiene para no quemar la IP.
"""

import hashlib
import json
import os
import random
import re
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urljoin, urlparse

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"


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

try:
    from curl_cffi import requests
except ImportError:
    print("Instala curl_cffi: pip install curl_cffi", file=sys.stderr)
    sys.exit(1)
try:
    from bs4 import BeautifulSoup
except ImportError:
    print("Instala beautifulsoup4: pip install beautifulsoup4", file=sys.stderr)
    sys.exit(1)

# --- Config ---
SITEINDEX_URL = "https://www.walmart.com.mx/siteindex.xml"
BASE_WALMART = "https://www.walmart.com.mx"
TIENDA = "Walmart"
# TLS: impersonate Chrome para evitar detección por fingerprint
IMPERSONATE = "chrome110"
# User-Agent iPhone (móvil) — cabeceras de alta fidelidad
USER_AGENT = (
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"
)
REQUEST_TIMEOUT = 25
# Pausa entre peticiones a páginas de producto (evitar ráfagas de robot)
DELAY_MIN, DELAY_MAX = 8, 15
CAPTCHA_PALABRAS = ("verifica tu identidad", "verify you are human", "captcha", "unusual traffic")
# Límite de URLs de producto a procesar por ejecución (evitar sobrecarga)
MAX_PRODUCTOS_POR_RUN = int(os.environ.get("WALMART_SITEMAP_MAX_PRODUCTOS", "200"))
# Palabras clave opcionales para filtrar URLs del sitemap (vacío = no filtrar)
KEYWORDS_FILTER = os.environ.get("WALMART_SITEMAP_KEYWORDS", "").strip().lower().split()

# URLs de secciones de ofertas / especiales: se extraen enlaces /ip/ (productos). Por defecto: 3 links (mismo criterio que el panel).
# En .env: WALMART_OFERTAS_URLS=url1,url2,url3 o configurar en panel Marketplaces → Walmart → URLs de secciones
OFERTAS_ENV = os.environ.get("WALMART_OFERTAS_URLS", "").strip()
OFERTAS_SECTION_URLS = [
    u.strip() for u in OFERTAS_ENV.split(",") if u.strip()
] if OFERTAS_ENV else [
    "https://www.walmart.com.mx/content/especiales/supermercado/360013_8048760?co_zn=contentSN1-sub-navegation&co_ty=WEB-OHWM-subnavegation&co_nm=Homepage&co_id=precios-bajos&co_or=super",
    "https://www.walmart.com.mx/shop/ofertas-flash-walmart?co_zn=contentSN2-sub-navegation&co_ty=WEB-OHWM-subnavegation&co_nm=Homepage&co_id=precios-bajos&co_or=lp-flash-deal",
    "https://www.walmart.com.mx/content/especiales/beneficios-walmart/360013_2480003?co_zn=contentSN2-sub-navegation&co_ty=WEB-OHWM-subnavegation&co_nm=Homepage&co_id=precios-bajos&co_or=promociones",
]

# Namespace estándar de sitemaps
SITEMAP_NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}


def _sku_desde_url(url: str) -> str:
    """Mismo criterio que scraper_walmart: /ip/.../ID → wm_ID; si no, MD5 de URL normalizada."""
    if not (url or "").strip():
        return hashlib.md5(b"").hexdigest()[:16]
    parsed = urlparse(url)
    path = (parsed.path or "").rstrip("/")
    url_normalizada = f"{parsed.scheme or 'https'}://{parsed.netloc or ''}{path}"
    if "/ip/" in path:
        segments = [s for s in path.split("/") if s]
        if segments and segments[-1].isdigit():
            return "wm_" + segments[-1]
    return hashlib.md5(url_normalizada.encode("utf-8")).hexdigest()[:16]


def _get_db_connection():
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


def _get_ofertas_urls_from_db() -> list[str]:
    """Lee URLs de secciones (ofertas) desde marketplaces.configuracion para slug=walmart."""
    try:
        conn = _get_db_connection()
        cursor = conn.cursor()
        cursor.execute(
            "SELECT configuracion FROM marketplaces WHERE slug = %s LIMIT 1",
            ("walmart",),
        )
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        if not row or not row[0]:
            return []
        raw = row[0]
        if isinstance(raw, str):
            data = json.loads(raw)
        else:
            data = raw
        urls = (data or {}).get("urls") if isinstance(data, dict) else []
        if not isinstance(urls, list):
            return []
        return [u for u in urls if isinstance(u, str) and u.strip().startswith("https://")]
    except Exception:
        return []


def _guardar_en_mysql(productos: list[dict]) -> None:
    if not productos:
        return
    import hashlib
    conn = _get_db_connection()
    cursor = conn.cursor()
    sql = """
    INSERT INTO productos (nombre, sku, precio_actual, precio_original, descuento, url_producto, url_producto_hash, url_imagen, tienda, created_at, updated_at)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        nombre          = VALUES(nombre),
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


def _parse_precio(texto: str) -> float | None:
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


def _session_headers(referer: str | None = None):
    h = {
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language": "es-MX,es;q=0.9,en;q=0.8",
    }
    if referer:
        h["Referer"] = referer
    return h


def _make_session():
    """Session curl_cffi con impersonate Chrome (TLS) y cabeceras de alta fidelidad."""
    return requests.Session(impersonate=IMPERSONATE)


def fetch_sitemap_index(session: requests.Session) -> list[str]:
    """Descarga siteindex.xml y devuelve lista de URLs de sitemaps (productSitemap*.xml)."""
    r = session.get(SITEINDEX_URL, headers=_session_headers(), timeout=REQUEST_TIMEOUT)
    r.raise_for_status()
    root = ET.fromstring(r.content)
    urls = []
    for loc in root.findall(".//sm:loc", SITEMAP_NS):
        if loc is not None and loc.text:
            url = loc.text.strip()
            if "productSitemap" in url or "product" in url.lower():
                urls.append(url)
    if not urls:
        for loc in root.findall(".//{http://www.sitemaps.org/schemas/sitemap/0.9}loc"):
            if loc is not None and loc.text:
                url = loc.text.strip()
                if "productSitemap" in url or "product" in url.lower():
                    urls.append(url)
    return urls


def _url_absoluta(href: str, base: str = BASE_WALMART) -> str | None:
    """Convierte href a URL absoluta (walmart.com.mx)."""
    if not href or not href.strip():
        return None
    h = href.strip().split("?")[0].split("#")[0]
    if not h or "/ip/" not in h:
        return None
    if h.startswith("//"):
        h = "https:" + h
    elif h.startswith("/"):
        h = urljoin(base, h)
    if "walmart.com.mx" not in h:
        return None
    return h


def fetch_product_urls_from_ofertas_page(session: requests.Session, page_url: str) -> list[str]:
    """Descarga una página de sección ofertas y devuelve todas las URLs de producto (/ip/)."""
    r = session.get(page_url, headers=_session_headers(), timeout=REQUEST_TIMEOUT)
    r.raise_for_status()
    soup = BeautifulSoup(r.text, "html.parser")
    urls = []
    for a in soup.find_all("a", href=True):
        href = a["href"]
        abs_url = _url_absoluta(href, page_url)
        if abs_url:
            urls.append(abs_url)
    return list(dict.fromkeys(urls))


def fetch_product_urls_from_sitemap(session: requests.Session, sitemap_url: str) -> list[str]:
    """Descarga un sitemap XML y devuelve las <loc> que sean páginas de producto (/ip/)."""
    r = session.get(sitemap_url, headers=_session_headers(), timeout=REQUEST_TIMEOUT)
    r.raise_for_status()
    root = ET.fromstring(r.content)
    urls = []
    for loc in root.findall(".//sm:loc", SITEMAP_NS):
        if loc is not None and loc.text:
            u = loc.text.strip()
            if "/ip/" in u and "walmart.com.mx" in u:
                urls.append(u)
    if not urls:
        for loc in root.findall(".//{http://www.sitemaps.org/schemas/sitemap/0.9}loc"):
            if loc is not None and loc.text:
                u = loc.text.strip()
                if "/ip/" in u and "walmart.com.mx" in u:
                    urls.append(u)
    return urls


def filter_by_keywords(urls: list[str]) -> list[str]:
    if not KEYWORDS_FILTER:
        return urls
    filtered = []
    for u in urls:
        lower = u.lower()
        if any(k in lower for k in KEYWORDS_FILTER):
            filtered.append(u)
    return filtered


def _is_captcha_page(html: str, title: str | None) -> bool:
    """True si la página es captcha / Verifica tu identidad (no guardamos ese producto)."""
    text = ((title or "") + " " + (html[:8000] if html else "")).lower()
    return any(p in text for p in CAPTCHA_PALABRAS)


def extract_product_from_html(html: str, url: str) -> dict | None:
    """Extrae nombre, precio y opcionalmente imagen del HTML de la página de producto.
    Si la página es captcha (Verifica tu identidad), devuelve None para no guardar basura.
    """
    soup = BeautifulSoup(html, "html.parser")

    nombre = None
    meta_og = soup.find("meta", property="og:title")
    if meta_og and meta_og.get("content"):
        nombre = meta_og["content"].strip()
    if not nombre and soup.title:
        nombre = soup.title.string.strip() if soup.title.string else None
    if not nombre:
        nombre = "Producto Walmart"

    if _is_captcha_page(html, nombre):
        return None

    precio_actual = None
    precio_original = None

    # JSON-LD (schema.org Product / Offer)
    for script in soup.find_all("script", type="application/ld+json"):
        if not script.string:
            continue
        try:
            data = json.loads(script.string)
            if isinstance(data, list):
                for item in data:
                    if isinstance(item, dict):
                        p, o = _precio_desde_ld(item)
                        if p is not None:
                            precio_actual = p
                            precio_original = o or p
                            break
            elif isinstance(data, dict):
                precio_actual, precio_original = _precio_desde_ld(data)
            if precio_actual is not None:
                break
        except Exception:
            continue

    # Fallback: buscar en texto/atributos típicos de precio
    if precio_actual is None:
        for tag in soup.find_all(string=re.compile(r"\$?\s*\d{1,3}(,\d{3})*(\.\d{2})?")):
            parent = tag.parent
            if parent and parent.name:
                text = tag if isinstance(tag, str) else (tag.string or "")
                val = _parse_precio(text)
                if val and 0.01 < val < 1_000_000:
                    precio_actual = val
                    precio_original = val
                    break

    if precio_actual is None:
        precio_actual = 0.0
        precio_original = None
    if precio_original is None:
        precio_original = precio_actual
    descuento = 0
    if precio_original and precio_original > precio_actual and precio_original > 0:
        descuento = int(round((1 - precio_actual / precio_original) * 100))

    url_imagen = None
    img = soup.find("meta", property="og:image")
    if img and img.get("content"):
        url_imagen = img["content"].strip()

    return {
        "nombre": nombre[:500] if nombre else "Producto Walmart",
        "sku": _sku_desde_url(url),
        "precio_actual": round(float(precio_actual), 2),
        "precio_original": round(float(precio_original), 2) if precio_original is not None else None,
        "descuento": descuento,
        "url_producto": url,
        "url_imagen": url_imagen or "",
        "tienda": TIENDA,
    }


def _precio_desde_ld(obj: dict):
    """Extrae (precio_actual, precio_original) desde JSON-LD (Product con offers)."""
    if isinstance(obj.get("offers"), dict):
        off = obj["offers"]
        p = off.get("price")
        if p is not None:
            try:
                return float(p), float(off.get("highPrice") or p)
            except (TypeError, ValueError):
                pass
    if isinstance(obj.get("offers"), list) and obj["offers"]:
        off = obj["offers"][0]
        p = off.get("price")
        if p is not None:
            try:
                return float(p), float(p)
            except (TypeError, ValueError):
                pass
    for key in ("price", "lowPrice", "currentPrice"):
        if obj.get(key) is not None:
            try:
                v = float(obj[key])
                o = obj.get("highPrice")
                return v, float(o) if o is not None else v
            except (TypeError, ValueError):
                pass
    return None, None


def _resolve_ofertas_section_urls() -> tuple[list[str], bool]:
    """URLs de ofertas: primero desde BD (panel Filament), si no desde env/default. Devuelve (urls, desde_bd)."""
    from_db = _get_ofertas_urls_from_db()
    if from_db:
        return from_db, True
    return OFERTAS_SECTION_URLS, False


def main():
    all_product_urls = []
    ofertas_urls, ofertas_desde_bd = _resolve_ofertas_section_urls()

    # --- Paso 1: URLs desde secciones de ofertas (prioridad) ---
    print("[1/5] Extrayendo URLs de productos desde secciones de ofertas...")
    if ofertas_desde_bd:
        print("      (URLs desde panel: Marketplaces → Walmart → URLs de secciones)")
    else:
        print("      (URLs por defecto / WALMART_OFERTAS_URLS)")
    session = _make_session()
    for section_url in ofertas_urls:
        try:
            urls = fetch_product_urls_from_ofertas_page(session, section_url)
            all_product_urls.extend(urls)
            print(f"      {section_url[:55]}... → {len(urls)} productos")
            time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))
        except Exception as e:
            print(f"      [WARN] Ofertas {section_url[:50]}... → {e}")
    all_product_urls = list(dict.fromkeys(all_product_urls))
    num_ofertas = len(all_product_urls)

    # --- Paso 2: Sitemap índice ---
    print("[2/5] Leyendo sitemap índice:", SITEINDEX_URL)
    try:
        sitemap_urls = fetch_sitemap_index(session)
    except Exception as e:
        print(f"[WARN] Sitemap índice no disponible: {e}")
        sitemap_urls = []
    if sitemap_urls:
        print(f"      Encontrados {len(sitemap_urls)} sitemaps de productos.")

    # --- Paso 3: URLs desde sitemaps (añadir sin duplicar) ---
    print("[3/5] Recopilando URLs desde sitemaps...")
    seen = set(all_product_urls)
    for sm_url in sitemap_urls[:20]:
        try:
            urls = fetch_product_urls_from_sitemap(session, sm_url)
            for u in urls:
                if u not in seen:
                    seen.add(u)
                    all_product_urls.append(u)
            time.sleep(random.uniform(1, 3))
        except Exception as e:
            print(f"      [WARN] Sitemap {sm_url}: {e}")
    if KEYWORDS_FILTER:
        all_product_urls = filter_by_keywords(all_product_urls)
    all_product_urls = all_product_urls[:MAX_PRODUCTOS_POR_RUN]
    print(f"      Total: {len(all_product_urls)} URLs (ofertas: {num_ofertas}, máx por run: {MAX_PRODUCTOS_POR_RUN}).")
    if not all_product_urls:
        print("[WARN] No hay URLs de producto (ofertas ni sitemap). Revisa WALMART_OFERTAS_URLS o conectividad.")
        sys.exit(0)

    print("[4/5] Extrayendo nombre y precio de cada producto (curl_cffi + impersonate chrome110)...")
    productos = []
    captcha_detectado = False
    for i, url in enumerate(all_product_urls):
        try:
            r = session.get(
                url,
                headers=_session_headers(referer=BASE_WALMART + "/"),
                timeout=REQUEST_TIMEOUT,
            )
            r.raise_for_status()
            p = extract_product_from_html(r.text, url)
            if p:
                productos.append(p)
            else:
                captcha_detectado = True
                print("")
                print("[STOP] Detectada página «Verifica tu identidad». Se detiene para no quemar la IP.")
                print(f"       Productos obtenidos hasta ahora: {len(productos)}. Guardando y saliendo.")
                break
        except Exception as e:
            print(f"      [WARN] {url[:60]}... → {e}")
        if (i + 1) % 10 == 0 and not captcha_detectado:
            print(f"      Procesados {i + 1}/{len(all_product_urls)} (guardados: {len(productos)})")
        time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    if captcha_detectado:
        print("")
        print("--- Walmart mostró «Verifica tu identidad». No se siguieron haciendo peticiones. ---")
        print("Opciones: ejecutar desde otra red (PC) o usar scraper con navegador (Playwright).")
        print("")
    print(f"[5/5] Guardando {len(productos)} productos en la BD...")
    _guardar_en_mysql(productos)
    print("Listo.")


if __name__ == "__main__":
    main()
