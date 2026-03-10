#!/usr/bin/env python3
"""
Scraper Coppel México (sin navegador).
- Páginas /l/ y /ca/: extrae productos del HTML (SSR, productcard-container).
- Páginas /sd/ (categorías de outlet): usa la API GraphQL de Coppel con JWT anónimo.
- Guarda en tabla productos con tienda='Coppel'.
"""

import hashlib
import json
import os
import random
import re
import sys
import time
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

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
TIENDA = "Coppel"
BASE_COPPEL = "https://www.coppel.com"
IMPERSONATE = "chrome110"
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36"
)
REQUEST_TIMEOUT = 30
DELAY_MIN, DELAY_MAX = 3, 7
MAX_PAGINAS = int(os.environ.get("COPPEL_MAX_PAGINAS", "10"))
MAX_PRODUCTOS_POR_RUN = int(os.environ.get("COPPEL_MAX_PRODUCTOS", "500"))
GQL_PAGE_SIZE = 48

# Secciones por defecto (overridable desde BD → marketplaces.configuracion.urls para slug=coppel)
SECCIONES_DEFAULT = [
    "https://www.coppel.com/l/ofertas",
    "https://www.coppel.com/sd/RB2514EPMTPEMOODS?mejores_ofertas=true",
    "https://www.coppel.com/ca/outlet-saldos",
]

BLOQUEO_PALABRAS = ("access denied", "bot detected", "cloudflare", "just a moment")

# Directorio donde se guardan imágenes descargadas localmente
IMAGES_DIR = PROJECT_ROOT / "storage" / "app" / "public" / "imagenes" / "coppel"
APP_URL = os.environ.get("APP_URL", "http://localhost")  # se sobreescribe con .env

# GraphQL query para productos (campos reales del schema de Coppel)
GQL_QUERY = """
fragment baseProductFields on LucidProduct {
  name
  thumbnail
  sku
  href
  price {
    discountedPrice
    salesPrice
  }
}
query GET_SEARCH(
  $pageNumber: Int
  $pageSize: Int
  $searchTerm: String!
  $nodeContext: NodeContext
) {
  getSearchResults(input: {
    pageNumber: $pageNumber
    pageSize: $pageSize
    searchTerm: $searchTerm
    nodeContext: $nodeContext
  }) {
    totalCount
    products {
      ...baseProductFields
    }
  }
}
"""


# ---------- Descarga de imágenes ----------

def _descargar_imagen(session: requests.Session, url_imagen: str) -> str:
    """
    Descarga la imagen de Coppel con curl_cffi (bypasa bloqueo de CDN).
    La guarda en storage/app/public/imagenes/coppel/ y retorna la URL pública local.
    Si falla, retorna la URL original.
    """
    if not url_imagen or not url_imagen.startswith("http"):
        return url_imagen

    # Limpiar parámetros del URL para obtener nombre limpio
    clean_url = strtok_url(url_imagen)
    path = urlparse(clean_url).path.rstrip("/")
    filename = path.split("/")[-1] if "/" in path else hashlib.md5(clean_url.encode()).hexdigest() + ".jpg"
    # Asegurar extensión válida
    if not any(filename.endswith(ext) for ext in (".jpg", ".jpeg", ".png", ".webp", ".avif")):
        filename += ".jpg"

    dest = IMAGES_DIR / filename
    if dest.exists() and dest.stat().st_size > 500:
        # Ya descargada antes
        return f"{APP_URL}/storage/imagenes/coppel/{filename}"

    try:
        IMAGES_DIR.mkdir(parents=True, exist_ok=True)
        r = session.get(
            clean_url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
                "Referer": BASE_COPPEL + "/",
            },
            timeout=20,
        )
        if r.status_code == 200 and len(r.content) > 500:
            # Detectar extensión real por Content-Type
            ct = r.headers.get("content-type", "").lower()
            if "avif" in ct:
                real_ext = ".avif"
            elif "webp" in ct:
                real_ext = ".webp"
            elif "png" in ct:
                real_ext = ".png"
            else:
                real_ext = ".jpg"

            # Renombrar si la extensión detectada es diferente
            stem = filename.rsplit(".", 1)[0]
            final_filename = stem + real_ext
            dest = IMAGES_DIR / final_filename

            dest.write_bytes(r.content)
            return f"{APP_URL}/storage/imagenes/coppel/{final_filename}"
    except Exception as e:
        pass  # Si falla, usar URL original

    return url_imagen


def strtok_url(url: str) -> str:
    """Quita query string de una URL."""
    return url.split("?")[0]


# ---------- Session helpers ----------

def _html_headers(referer: str | None = None) -> dict:
    h = {
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language": "es-MX,es;q=0.9,en;q=0.8",
    }
    if referer:
        h["Referer"] = referer
    return h


def _make_session() -> requests.Session:
    return requests.Session(impersonate=IMPERSONATE)


# ---------- JWT anónimo de Coppel ----------

_jwt_cache: dict = {}


def _obtener_jwt(session: requests.Session) -> str:
    """Obtiene (o reutiliza) un JWT anónimo de la API de Coppel."""
    if _jwt_cache.get("token"):
        return _jwt_cache["token"]
    try:
        r = session.post(
            BASE_COPPEL + "/auth/access-token",
            headers={
                "User-Agent": USER_AGENT,
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Referer": BASE_COPPEL + "/",
                "Origin": BASE_COPPEL,
            },
            json={},
            timeout=15,
        )
        token = r.json().get("access_token", "")
        if token:
            _jwt_cache["token"] = token
            print(f"  [JWT] Token obtenido ({len(token)} chars)")
        return token
    except Exception as e:
        print(f"  [WARN] No se pudo obtener JWT: {e}")
        return ""


# ---------- BD ----------

def _get_db_connection():
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    database = os.environ.get("DB_DATABASE", "mayoreo_cloud")
    user = os.environ.get("DB_USERNAME", "root")
    password = os.environ.get("DB_PASSWORD", "")
    if password and (password.startswith("'") or password.startswith('"')):
        password = password.strip("'\"").strip()
    return mysql.connector.connect(host=host, port=port, user=user, password=password, database=database)


def _get_secciones_from_db() -> list[str]:
    """Lee URLs de secciones desde marketplaces.configuracion para slug=coppel."""
    try:
        conn = _get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT configuracion FROM marketplaces WHERE slug = %s LIMIT 1", ("coppel",))
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        if not row or not row[0]:
            return []
        raw = row[0]
        if isinstance(raw, (bytes, bytearray)):
            raw = raw.decode("utf-8", errors="replace")
        data = json.loads(raw) if isinstance(raw, str) else raw
        urls = (data or {}).get("urls", []) if isinstance(data, dict) else []
        return [u for u in (urls or []) if isinstance(u, str) and u.strip().startswith("https://")]
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
            p["nombre"], p["sku"], p["precio_actual"], p["precio_original"],
            p["descuento"], p["url_producto"], url_hash, p["url_imagen"], p["tienda"],
        ))
    conn.commit()
    cursor.close()
    conn.close()
    print(f"[OK] Guardados/actualizados {len(productos)} productos Coppel en MySQL.")


# ---------- Helpers ----------

def _sku_desde_url(url: str) -> str:
    if not (url or "").strip():
        return hashlib.md5(b"").hexdigest()[:16]
    parsed = urlparse(url)
    path = (parsed.path or "").rstrip("/")
    url_norm = f"{parsed.scheme or 'https'}://{parsed.netloc or ''}{path}"
    m = re.search(r"[/-]pm[-/]?(\d+)", path)
    if m:
        return "cp_" + m.group(1)
    m = re.search(r"-(\d{5,})$", path)
    if m:
        return "cp_" + m.group(1)
    m = re.search(r"/p/([^/?#]+)", path)
    if m:
        return "cp_" + m.group(1)
    segs = [s for s in path.split("/") if s]
    if segs and re.match(r"^\d+$", segs[-1]):
        return "cp_" + segs[-1]
    return "cp_" + hashlib.md5(url_norm.encode()).hexdigest()[:14]


def _parse_precio(texto: str) -> float | None:
    if not texto:
        return None
    s = re.sub(r"[^\d,.\s]", "", str(texto).strip()).replace(" ", "")
    if "," in s and "." in s:
        s = s.replace(",", "") if s.rfind(".") > s.rfind(",") else s.replace(".", "").replace(",", ".")
    elif "," in s:
        s = s.replace(",", ".") if s.count(",") == 1 and len(s.split(",")[-1]) <= 2 else s.replace(",", "")
    try:
        return float(s)
    except ValueError:
        return None


def _es_bloqueo(html: str) -> bool:
    sample = html[:5000].lower()
    return any(p in sample for p in BLOQUEO_PALABRAS)


def _url_es_sd(url: str) -> bool:
    """Retorna True si es una URL de categoría /sd/ que necesita GraphQL."""
    return "/sd/" in url


def _extraer_category_id(url: str) -> str:
    """Extrae el ID de categoría del path /sd/CATEGORY_ID."""
    parsed = urlparse(url)
    parts = [p for p in parsed.path.split("/") if p]
    if len(parts) >= 2 and parts[0] == "sd":
        return parts[1]
    return ""


def _extraer_node_context(url: str) -> dict | None:
    """Extrae pmNodeId y prNodeId de los query params de la URL."""
    parsed = urlparse(url)
    params = parse_qs(parsed.query)
    pm = params.get("pmNodeId", [None])[0]
    pr = params.get("prNodeId", [None])[0]
    if pm or pr:
        ctx: dict = {}
        if pm:
            ctx["pmNodeId"] = pm
        if pr:
            ctx["prNodeId"] = pr
        return ctx
    return None


# ---------- API GraphQL de Coppel ----------

def _gql_headers(token: str, referer: str) -> dict:
    return {
        "User-Agent": USER_AGENT,
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "Origin": BASE_COPPEL,
        "Referer": referer,
        "x-channel": "web",
        "Accept-Language": "es-MX,es;q=0.9",
    }


def _producto_desde_gql(item: dict) -> dict | None:
    """Convierte un item del API GraphQL de Coppel en un dict producto."""
    nombre = (item.get("name") or "").strip()
    if not nombre:
        return None

    sku_raw = str(item.get("sku") or "").strip()
    sku = ("cp_" + sku_raw) if sku_raw else _sku_desde_url(item.get("href", ""))

    href = item.get("href", "")
    url_producto = urljoin(BASE_COPPEL, href) if href and not href.startswith("http") else href

    thumbnail = item.get("thumbnail") or ""
    # Coppel thumbnails: https://cdn5.coppel.com/images/catalog/pm/SKU-1.jpg
    url_imagen = thumbnail if thumbnail.startswith("http") else (BASE_COPPEL + thumbnail if thumbnail else "")

    precio_info = item.get("price") or {}
    precio_actual = precio_info.get("discountedPrice") or precio_info.get("salesPrice")
    precio_original = precio_info.get("salesPrice") or precio_actual

    if precio_actual is None:
        return None

    precio_actual = float(precio_actual)
    precio_original = float(precio_original or precio_actual)
    if precio_original < precio_actual:
        precio_original = precio_actual

    descuento = 0
    if precio_original > precio_actual:
        descuento = int(round((1 - precio_actual / precio_original) * 100))

    return {
        "nombre": nombre[:500],
        "sku": sku[:100],
        "precio_actual": round(precio_actual, 2),
        "precio_original": round(precio_original, 2),
        "descuento": descuento,
        "url_producto": url_producto,
        "url_imagen": url_imagen,
        "tienda": TIENDA,
    }


def _scrape_seccion_gql(session: requests.Session, url: str, token: str) -> list[dict]:
    """Scrape una sección /sd/ usando la API GraphQL de Coppel."""
    category_id = _extraer_category_id(url)
    node_ctx = _extraer_node_context(url)

    # Convertir el category_id del slug a un searchTerm legible
    # RB2520EPMTPEHOMBRESOUTLET → se usa directamente como searchTerm
    # La API acepta el ID como searchTerm
    search_term = category_id

    productos = []
    total_esperado = None
    page = 0

    for pagina in range(1, MAX_PAGINAS + 1):
        print(f"      Pág {pagina} (GraphQL): categoryId={category_id}, page={page}...")
        try:
            payload = {
                "query": GQL_QUERY,
                "variables": {
                    "pageNumber": page,
                    "pageSize": GQL_PAGE_SIZE,
                    "searchTerm": search_term,
                    "nodeContext": node_ctx,
                },
                "operationName": "GET_SEARCH",
            }
            r = session.post(
                BASE_COPPEL + "/graphql",
                headers=_gql_headers(token, url),
                json=payload,
                timeout=REQUEST_TIMEOUT,
            )
            r.raise_for_status()
        except Exception as e:
            print(f"      [WARN] Error GraphQL: {e}")
            break

        resp = r.json()
        if resp.get("errors"):
            err_msg = (resp["errors"][0] or {}).get("message", "?")
            print(f"      [WARN] GraphQL error: {err_msg[:150]}")
            break

        data = (resp.get("data") or {}).get("getSearchResults") or {}
        items = data.get("products") or []
        if total_esperado is None:
            total_esperado = data.get("totalCount")

        if not items:
            print(f"             Sin productos en pág {pagina}. Fin.")
            break

        for item in items:
            p = _producto_desde_gql(item)
            if p:
                productos.append(p)

        print(f"             +{len(items)} productos (GraphQL). Total acumulado: {len(productos)}/{total_esperado}")

        # Paginación: si recibimos menos que el page_size o llegamos al total, parar
        if total_esperado and len(productos) >= total_esperado:
            print("             Todos los productos obtenidos.")
            break
        if len(items) < GQL_PAGE_SIZE:
            print("             Última página (menos de pageSize). Fin.")
            break

        page += 1
        time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    return productos


# ---------- Extracción HTML (para /l/ y /ca/) ----------

def _extraer_desde_ld_json(soup: BeautifulSoup, page_url: str) -> list[dict]:
    """Extrae productos desde <script type='application/ld+json'>."""
    productos = []
    for script in soup.find_all("script", type="application/ld+json"):
        if not script.string:
            continue
        try:
            data = json.loads(script.string)
        except Exception:
            continue
        items = data if isinstance(data, list) else [data]
        for item in items:
            tipo = item.get("@type", "")
            if tipo not in ("Product", "ItemList"):
                continue
            if tipo == "ItemList":
                for el in item.get("itemListElement", []):
                    p = _producto_desde_ld(el.get("item") or el, page_url)
                    if p:
                        productos.append(p)
            else:
                p = _producto_desde_ld(item, page_url)
                if p:
                    productos.append(p)
    return productos


def _producto_desde_ld(obj: dict, page_url: str) -> dict | None:
    if not isinstance(obj, dict):
        return None
    nombre = (obj.get("name") or "").strip()
    if not nombre:
        return None

    url_producto = obj.get("url") or page_url
    if url_producto and url_producto.startswith("/"):
        url_producto = urljoin(BASE_COPPEL, url_producto)

    url_imagen = ""
    img = obj.get("image")
    if isinstance(img, list):
        url_imagen = img[0] if img else ""
    elif isinstance(img, str):
        url_imagen = img

    precio_actual = None
    precio_original = None
    offers = obj.get("offers") or obj.get("Offers")
    if isinstance(offers, dict):
        offers = [offers]
    if isinstance(offers, list) and offers:
        off = offers[0]
        precio_actual = _parse_precio(str(off.get("price", "") or ""))
        precio_original = _parse_precio(str(off.get("highPrice") or off.get("price", "") or ""))
    if precio_actual is None:
        return None
    if precio_original is None or precio_original < precio_actual:
        precio_original = precio_actual

    descuento = 0
    if precio_original and precio_original > precio_actual:
        descuento = int(round((1 - precio_actual / precio_original) * 100))

    sku_raw = obj.get("sku") or obj.get("gtin13") or obj.get("gtin8") or obj.get("mpn") or ""
    sku = ("cp_" + str(sku_raw).strip()) if sku_raw else _sku_desde_url(url_producto)

    return {
        "nombre": nombre[:500],
        "sku": sku[:100],
        "precio_actual": round(float(precio_actual), 2),
        "precio_original": round(float(precio_original), 2),
        "descuento": descuento,
        "url_producto": url_producto,
        "url_imagen": url_imagen,
        "tienda": TIENDA,
    }


def _extraer_desde_html(soup: BeautifulSoup, page_url: str) -> list[dict]:
    """Extrae productos del HTML de páginas /l/ de Coppel."""
    productos = []

    cards = soup.select("div.productcard-container")
    if not cards:
        cards = [
            a.find_parent("div") for a in soup.select("a[href*='/pdp/'], a[href*='/pm/']")
            if a.find_parent("div")
        ]
        seen_cards: list = []
        for c in cards:
            if c not in seen_cards:
                seen_cards.append(c)
        cards = seen_cards

    for card in cards:
        try:
            a_prod = card.select_one("a[href*='/pdp/'], a[href*='/pm/']")
            if not a_prod:
                a_prod = card.find_parent("a") or card.select_one("a[href]")
            if not a_prod:
                continue
            href = a_prod.get("href", "")
            url_prod = urljoin(BASE_COPPEL, href) if not href.startswith("http") else href

            h3 = card.select_one("h3")
            if h3:
                nombre = h3.get("title") or h3.get_text(strip=True)
            else:
                nombre = a_prod.get_text(strip=True)
            nombre = nombre.strip()
            if not nombre:
                continue

            precio_actual = None
            precio_original = None
            spans_precio = card.select("p span, span")
            precios_encontrados: list[float] = []
            for sp in spans_precio:
                txt = sp.get_text(strip=True).replace(" ", "").replace("<!-- -->", "")
                val = _parse_precio(txt)
                if val and val > 0:
                    precios_encontrados.append(val)

            tachado_el = card.select_one("span.line-through, s, del, [class*='line-through']")
            if tachado_el:
                txt_tachado = tachado_el.get_text(strip=True).replace(" ", "").replace("<!-- -->", "")
                precio_original = _parse_precio(txt_tachado)

            if precios_encontrados:
                precio_actual = min(precios_encontrados) if len(precios_encontrados) > 1 else precios_encontrados[0]
            if precio_actual is None:
                continue
            if precio_original is None or precio_original < precio_actual:
                precio_original = precio_actual

            descuento = 0
            if precio_original > precio_actual:
                descuento = int(round((1 - precio_actual / precio_original) * 100))

            url_imagen = ""
            wrapper = card.parent if card.parent else card
            img_el = wrapper.select_one(
                "picture img[src], picture img[data-src], "
                "img[src*='cdn5.coppel.com'], img[src*='coppel'], img[src]"
            )
            if img_el:
                src = img_el.get("src") or img_el.get("data-src") or ""
                source_el = wrapper.select_one("source[srcset]")
                if source_el:
                    srcset = source_el.get("srcset", "")
                    first = srcset.split(",")[0].strip().split(" ")[0]
                    if first.startswith("http"):
                        src = first
                url_imagen = src

            productos.append({
                "nombre": nombre[:500],
                "sku": _sku_desde_url(url_prod),
                "precio_actual": round(float(precio_actual), 2),
                "precio_original": round(float(precio_original), 2),
                "descuento": descuento,
                "url_producto": url_prod,
                "url_imagen": url_imagen,
                "tienda": TIENDA,
            })
        except Exception:
            continue
    return productos


def _siguiente_pagina(soup: BeautifulSoup, url_actual: str, pagina: int) -> str | None:
    for sel in ["a[aria-label='Siguiente']", "a[rel='next']", "a[class*='next']",
                "li[class*='next'] a"]:
        el = soup.select_one(sel)
        if el and el.get("href"):
            href = el["href"]
            return urljoin(url_actual, href) if not href.startswith("http") else href
    parsed = urlparse(url_actual)
    params = dict(p.split("=", 1) for p in parsed.query.split("&") if "=" in p) if parsed.query else {}
    for key in ("page", "pagina", "pg", "p"):
        if key in params:
            try:
                params[key] = str(int(params[key]) + 1)
                new_query = "&".join(f"{k}={v}" for k, v in params.items())
                return parsed._replace(query=new_query).geturl()
            except ValueError:
                pass
    return None


def _scrape_seccion_html(session: requests.Session, url: str) -> list[dict]:
    """Scrape una sección /l/ o /ca/ extrayendo del HTML."""
    productos = []
    url_actual = url
    for pagina in range(1, MAX_PAGINAS + 1):
        print(f"      Pág {pagina} (HTML): {url_actual[:70]}...")
        try:
            r = session.get(url_actual, headers=_html_headers(referer=BASE_COPPEL + "/"), timeout=REQUEST_TIMEOUT)
            r.raise_for_status()
        except Exception as e:
            print(f"      [WARN] Error GET: {e}")
            break

        html = r.text
        if _es_bloqueo(html):
            print(f"      [STOP] Detectado bloqueo en {url_actual[:60]}.")
            break

        soup = BeautifulSoup(html, "html.parser")

        from_ld = _extraer_desde_ld_json(soup, url_actual)
        if from_ld:
            productos.extend(from_ld)
            print(f"             +{len(from_ld)} productos (JSON-LD). Total: {len(productos)}")
        else:
            from_html = _extraer_desde_html(soup, url_actual)
            productos.extend(from_html)
            print(f"             +{len(from_html)} productos (HTML). Total: {len(productos)}")

        if not from_ld and not from_html:
            print("             Sin productos. Fin de paginación.")
            break

        next_url = _siguiente_pagina(soup, url_actual, pagina)
        if not next_url or next_url == url_actual:
            print("             No hay página siguiente. Fin.")
            break
        url_actual = next_url
        time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    return productos


# ---------- Main ----------

def main():
    secciones = _get_secciones_from_db() or SECCIONES_DEFAULT
    print(f"[Coppel] Secciones a scrapear: {len(secciones)}")
    for u in secciones:
        print(f"  - {u[:80]}")

    session = _make_session()
    todos: dict[str, dict] = {}

    # Obtener JWT una vez para todas las secciones que lo necesiten
    secciones_sd = [u for u in secciones if _url_es_sd(u)]
    jwt_token = ""
    if secciones_sd:
        print("\n[Auth] Obteniendo JWT anónimo de Coppel...")
        jwt_token = _obtener_jwt(session)
        if not jwt_token:
            print("  [WARN] No se pudo obtener JWT. Las secciones /sd/ se omitirán.")

    for idx, url in enumerate(secciones, 1):
        print(f"\n[{idx}/{len(secciones)}] Sección: {url[:70]}...")
        try:
            if _url_es_sd(url):
                if not jwt_token:
                    print("  [SKIP] Sin JWT disponible, omitiendo sección /sd/.")
                    lista = []
                else:
                    lista = _scrape_seccion_gql(session, url, jwt_token)
            else:
                lista = _scrape_seccion_html(session, url)
        except Exception as e:
            print(f"  [ERROR] {e}")
            lista = []

        for p in lista:
            if p["sku"] not in todos:
                todos[p["sku"]] = p
        print(f"  Sección {idx} terminada. Únicos acumulados: {len(todos)}")
        if idx < len(secciones):
            time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    productos = list(todos.values())[:MAX_PRODUCTOS_POR_RUN]

    # Descargar imágenes localmente para que Telegram pueda enviarlas
    print(f"\n[Imágenes] Descargando {len(productos)} imágenes localmente...")
    descargadas = 0
    for p in productos:
        if p.get("url_imagen"):
            nueva_url = _descargar_imagen(session, p["url_imagen"])
            if nueva_url != p["url_imagen"]:
                p["url_imagen"] = nueva_url
                descargadas += 1
    print(f"  {descargadas} imágenes descargadas. {len(productos) - descargadas} ya existían o fallaron.")

    print(f"\n[Guardando] {len(productos)} productos únicos en la BD...")
    _guardar_en_mysql(productos)
    print("Listo.")


if __name__ == "__main__":
    main()
