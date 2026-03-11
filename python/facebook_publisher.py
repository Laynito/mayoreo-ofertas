#!/usr/bin/env python3
"""
Publica en la Fan Page de Facebook las ofertas con mayor descuento que no se hayan publicado hoy.
Opcionalmente también publica en grupos (FB_GROUP_IDS + FB_USER_ACCESS_TOKEN con permiso publish_to_groups).

Credenciales: primero lee del panel (Marketplace → Facebook → Datos de la app de Facebook).
Si no hay Page ID o Token ahí, usa .env (FB_PAGE_ACCESS_TOKEN, FB_PAGE_ID).

Uso:
  python/venv/bin/python python/facebook_publisher.py
"""

import json
import os
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"

# Cargar .env antes de imports que usan variables de entorno
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
    import requests
except ImportError:
    print("Instala requests en el venv: python/venv/bin/pip install requests", file=sys.stderr)
    sys.exit(1)

# --- Config ---
TOP_N = int(os.environ.get("FB_TOP_N", "3"))
APP_URL = (os.environ.get("APP_URL") or "http://localhost").rstrip("/")
GRAPH_API_VERSION = "v20.0"
DELAY_BETWEEN_POSTS = 8  # segundos entre publicaciones
DELAY_BETWEEN_GROUPS = 5  # segundos entre publicaciones en distintos grupos


def _get_db_connection():
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    database = os.environ.get("DB_DATABASE", "mayoreo_cloud")
    user = os.environ.get("DB_USERNAME", "root")
    password = os.environ.get("DB_PASSWORD", "")
    if password and (password.startswith("'") or password.startswith('"')):
        password = password.strip("'\"").strip()
    return mysql.connector.connect(
        host=host, port=port, user=user, password=password, database=database
    )


def _credenciales_facebook_desde_bd() -> tuple[str | None, str | None]:
    """
    Lee Page ID y Token del marketplace Facebook en la BD (panel → Marketplace → Facebook).
    Devuelve (page_id, access_token) o (None, None) si no hay o no está configurado.
    """
    try:
        conn = _get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT configuracion FROM marketplaces WHERE slug = %s LIMIT 1",
            ("facebook",),
        )
        row = cursor.fetchone()
        cursor.close()
        conn.close()
        if not row or not row.get("configuracion"):
            return None, None
        cfg = row["configuracion"]
        if isinstance(cfg, str):
            cfg = json.loads(cfg) if cfg.strip() else {}
        if not isinstance(cfg, dict):
            return None, None
        page_id = (cfg.get("fb_page_id") or "").strip() or None
        token = (cfg.get("fb_page_access_token") or "").strip() or None
        return page_id, token
    except Exception:
        return None, None


def _productos_no_publicados_hoy(limit: int) -> list[dict]:
    """
    Los N productos con mayor % de descuento (solo 50% o más) que no hayan sido publicados hoy.
    Orden: de mayor a menor descuento del día.
    """
    conn = _get_db_connection()
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT id, nombre, precio_actual, precio_original, descuento, url_producto, url_afiliado, url_imagen, tienda
        FROM productos
        WHERE url_afiliado IS NOT NULL AND url_afiliado != ''
          AND url_imagen IS NOT NULL AND url_imagen != ''
          AND descuento >= 50
          AND (last_published_at IS NULL OR DATE(last_published_at) < CURDATE())
        ORDER BY descuento DESC
        LIMIT %s
        """,
        (limit,),
    )
    rows = cursor.fetchall()
    cursor.close()
    conn.close()
    return rows


def _marcar_publicado(producto_id: int) -> None:
    """Marca el producto como publicado hoy para no repetirlo."""
    conn = _get_db_connection()
    cursor = conn.cursor()
    cursor.execute(
        "UPDATE productos SET last_published_at = NOW() WHERE id = %s",
        (producto_id,),
    )
    conn.commit()
    cursor.close()
    conn.close()


def _imagen_publica(url_imagen: str | None) -> str | None:
    """Convierte URL local (localhost/storage/...) a URL pública para que Facebook pueda descargarla."""
    if not url_imagen or not url_imagen.strip():
        return None
    url = url_imagen.strip()
    parsed = urlparse(url)
    host = (parsed.netloc or "").lower()
    if host in ("localhost", "127.0.0.1"):
        path = parsed.path or ""
        return f"{APP_URL}{path}" if path.startswith("/") else f"{APP_URL}/{path}"
    if url.startswith("http://") or url.startswith("https://"):
        return url
    return f"{APP_URL}/{url.lstrip('/')}"


def _nombre_plataforma(tienda: str | None) -> str:
    """Nombre legible de la plataforma/tienda."""
    if not tienda or not str(tienda).strip():
        return "Oferta"
    t = str(tienda).strip().lower()
    if "mercado" in t or "ml" == t or "mercadolibre" in t:
        return "Mercado Libre"
    if "coppel" in t:
        return "Coppel"
    if "walmart" in t:
        return "Walmart"
    return tienda.strip().title()


def _texto_post(
    nombre: str,
    precio_actual,
    precio_original,
    link: str,
    descuento: int | float | None = None,
    tienda: str | None = None,
) -> str:
    """Texto llamativo para el post (incluye % descuento y plataforma)."""
    titulo = (nombre or "")[:120]
    precio = f"{float(precio_actual):.2f}" if precio_actual is not None else "—"
    antes = f"{float(precio_original):.2f}" if precio_original is not None else "—"
    plataforma = _nombre_plataforma(tienda)
    lineas = [
        "🔥 ¡OFERTA BOMBA! 🔥",
        "",
        f"📦 {titulo}",
        "",
        f"🛒 {plataforma}",
    ]
    if descuento is not None:
        try:
            pct = int(round(float(descuento)))
            lineas.append(f"📉 {pct}% OFF")
            lineas.append("")
        except (TypeError, ValueError):
            lineas.append("")
    lineas.extend([
        f"💰 A Solo ${precio} · Antes ${antes}",
        "",
        f"👉 Compra aquí: {link}",
    ])
    return "\n".join(lineas)


def _publicar_foto(page_id: str, access_token: str, image_url: str, message: str) -> bool:
    """Publica una foto en la Fan Page usando la API Graph de Facebook (requests)."""
    url = f"https://graph.facebook.com/{GRAPH_API_VERSION}/{page_id}/photos"
    payload = {
        "url": image_url,
        "message": message,
        "access_token": access_token,
    }
    try:
        r = requests.post(url, data=payload, timeout=30)
        data = r.json()
        if r.ok and data.get("id"):
            print(f"  [OK] Publicado (post_id: {data.get('id')})")
            return True
        print(f"  [ERROR] {r.status_code}: {data}", file=sys.stderr)
        return False
    except requests.RequestException as e:
        print(f"  [ERROR] {e}", file=sys.stderr)
        return False


def _grupos_configurados() -> list[str]:
    """Devuelve lista de IDs de grupos desde FB_GROUP_IDS (separados por coma)."""
    raw = (os.environ.get("FB_GROUP_IDS") or "").strip()
    if not raw:
        return []
    return [g.strip() for g in raw.split(",") if g.strip()]


def _publicar_en_grupo_feed(group_id: str, user_token: str, message: str, link: str) -> bool:
    """
    Publica en el feed del grupo (message + link). Requiere token de USUARIO con permiso publish_to_groups.
    El link muestra la vista previa (og:image) si la URL la tiene.
    """
    url = f"https://graph.facebook.com/{GRAPH_API_VERSION}/{group_id}/feed"
    payload = {
        "message": message,
        "link": link,
        "access_token": user_token,
    }
    try:
        r = requests.post(url, data=payload, timeout=30)
        data = r.json()
        if r.ok and data.get("id"):
            print(f"  [OK] Grupo {group_id} (post_id: {data.get('id')})")
            return True
        print(f"  [ERROR] Grupo {group_id} {r.status_code}: {data}", file=sys.stderr)
        return False
    except requests.RequestException as e:
        print(f"  [ERROR] Grupo {group_id} {e}", file=sys.stderr)
        return False


def main() -> int:
    if not os.environ.get("DB_HOST"):
        print("Configura DB_HOST (y DB_*) en .env para leer productos y credenciales.", file=sys.stderr)
        return 1

    # Credenciales: .env tiene prioridad; si falta algo, se usa el panel (Marketplace → Facebook)
    page_id = (os.environ.get("FB_PAGE_ID") or "").strip()
    token = (os.environ.get("FB_PAGE_ACCESS_TOKEN") or "").strip()
    if not page_id or not token:
        page_id_bd, token_bd = _credenciales_facebook_desde_bd()
        if not page_id:
            page_id = (page_id_bd or "").strip()
        if not token:
            token = (token_bd or "").strip()

    group_ids_early = _grupos_configurados()
    user_token_early = (os.environ.get("FB_USER_ACCESS_TOKEN") or "").strip()
    has_groups = bool(group_ids_early and user_token_early)

    if not token and not has_groups:
        print(
            "Configura al menos uno: Fan Page (FB_PAGE_ID + FB_PAGE_ACCESS_TOKEN) o Grupos (FB_GROUP_IDS + FB_USER_ACCESS_TOKEN).",
            file=sys.stderr,
        )
        return 1
    if not page_id and not has_groups:
        print(
            "Configura FB_PAGE_ID o FB_GROUP_IDS con FB_USER_ACCESS_TOKEN.",
            file=sys.stderr,
        )
        return 1

    # Verificar que el token sea de PÁGINA (no de usuario) solo si vamos a publicar en página
    if page_id and token:
        try:
            r = requests.get(
                f"https://graph.facebook.com/{GRAPH_API_VERSION}/me",
                params={"fields": "id,name", "access_token": token},
                timeout=10,
            )
            if r.ok:
                data = r.json()
                token_id = str(data.get("id", ""))
                token_name = data.get("name", "")
                if token_id != page_id:
                    print(
                        f"[AVISO] El token es de usuario '{token_name}' (id={token_id}), no de la página (id={page_id}).",
                        file=sys.stderr,
                    )
                    try:
                        r2 = requests.get(
                            f"https://graph.facebook.com/{GRAPH_API_VERSION}/me/accounts",
                            params={"access_token": token, "fields": "id,name,access_token"},
                            timeout=10,
                        )
                        if r2.ok:
                            data2 = r2.json()
                            for item in (data2.get("data") or []):
                                if str(item.get("id")) == page_id:
                                    page_token = (item.get("access_token") or "").strip()
                                    if page_token:
                                        print(
                                            "\n[SOLUCIÓN] Token de la PÁGINA (cópialo en .env como FB_PAGE_ACCESS_TOKEN):\n"
                                            f"{page_token}\n",
                                            file=sys.stderr,
                                        )
                                    break
                            else:
                                print(
                                    "No se encontró la página con id {} en me/accounts. "
                                    "Haz GET me/accounts en el Explorador y copia el 'access_token' de tu página."
                                    .format(page_id),
                                    file=sys.stderr,
                                )
                    except requests.RequestException:
                        print(
                            "Pasos: Explorador API Graph → Genera token (usuario) con pages_* → GET me/accounts "
                            "→ Copia el 'access_token' DENTRO del objeto de la página.",
                            file=sys.stderr,
                        )
                    return 1
                print(f"Token OK (página: {token_name})")
        except requests.RequestException:
            pass

    productos = _productos_no_publicados_hoy(TOP_N)
    if not productos:
        print("No hay ofertas elegibles (50%+ descuento, con imagen/afiliado y no publicadas hoy).")
        return 0

    group_ids = _grupos_configurados()
    user_token = (os.environ.get("FB_USER_ACCESS_TOKEN") or "").strip()
    publish_to_groups = bool(group_ids and user_token)
    if group_ids and not user_token:
        print("[AVISO] FB_GROUP_IDS está configurado pero falta FB_USER_ACCESS_TOKEN (token de usuario con publish_to_groups). Se publicará solo en la página.", file=sys.stderr)
    if publish_to_groups:
        print(f"También se publicará en {len(group_ids)} grupo(s).")

    if page_id and token:
        print(f"Publicando {len(productos)} oferta(s) en la Fan Page (máx. no publicadas hoy)...")
    else:
        print(f"Publicando {len(productos)} oferta(s) en grupo(s)...")
    ok = 0
    for i, p in enumerate(productos):
        link = (p.get("url_afiliado") or p.get("url_producto") or "").strip()
        if not link and not (page_id and token):
            print(f"  [SKIP] Sin link (necesario para grupos): {p.get('nombre', '')[:50]}")
            continue
        imagen = _imagen_publica(p.get("url_imagen"))
        if page_id and token and not imagen:
            print(f"  [SKIP] Sin imagen pública: {p.get('nombre', '')[:50]}")
            continue
        if not page_id and not link:
            continue

        nombre = p.get("nombre") or ""
        precio_actual = p.get("precio_actual")
        precio_original = p.get("precio_original")
        descuento = p.get("descuento")
        tienda = p.get("tienda")
        msg = _texto_post(nombre, precio_actual, precio_original, link or "#", descuento=descuento, tienda=tienda)

        print(f"[{i+1}/{len(productos)}] {nombre[:50]}...")
        page_ok = _publicar_foto(page_id, token, imagen, msg) if (page_id and token and imagen) else False

        if page_ok and publish_to_groups and link:
            for gi, gid in enumerate(group_ids):
                if gi > 0:
                    time.sleep(DELAY_BETWEEN_GROUPS)
                _publicar_en_grupo_feed(gid, user_token, msg, link)

        if page_ok:
            _marcar_publicado(p["id"])
            ok += 1
        elif publish_to_groups and link:
            # Sin página configurada o falló página: si al menos un grupo recibe el post, marcar como publicado
            group_ok = False
            for gid in group_ids:
                if _publicar_en_grupo_feed(gid, user_token, msg, link):
                    group_ok = True
                time.sleep(DELAY_BETWEEN_GROUPS)
            if group_ok:
                _marcar_publicado(p["id"])
                ok += 1

        if i < len(productos) - 1:
            time.sleep(DELAY_BETWEEN_POSTS)

    print(f"Listo. Publicados: {ok}/{len(productos)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
