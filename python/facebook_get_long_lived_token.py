#!/usr/bin/env python3
"""
Obtiene un token de PÁGINA que no caduca (o dura ~60+ días), a partir de un token
corto de usuario del Explorador de la API Graph.

Pasos:
  1. En https://developers.facebook.com/tools/explorer/ genera un token de usuario
     con permisos: pages_show_list, pages_read_engagement, pages_manage_posts.
  2. En developers.facebook.com → Tu app → Configuración → Básica: copia ID de app
     y Clave secreta de la app.
  3. Pon en .env: FB_APP_ID, FB_APP_SECRET y (opcional) FB_TOKEN_CORTO=token_del_paso_1.
  4. Ejecuta este script. Si no pusiste FB_TOKEN_CORTO, te pedirá pegarlo.
  5. El script imprime el token de la PÁGINA. Cópialo en .env como FB_PAGE_ACCESS_TOKEN.
     Ese token no caduca (o dura mucho más).

Uso:
  python/venv/bin/python python/facebook_get_long_lived_token.py
  # o pasando el token corto por argumento:
  python/venv/bin/python python/facebook_get_long_lived_token.py "EAAxxxx..."
"""

import os
import sys
from pathlib import Path

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

try:
    import requests
except ImportError:
    print("Instala requests: python/venv/bin/pip install requests", file=sys.stderr)
    sys.exit(1)

GRAPH_API_VERSION = "v20.0"


def main() -> int:
    app_id = (os.environ.get("FB_APP_ID") or "").strip()
    app_secret = (os.environ.get("FB_APP_SECRET") or "").strip()
    page_id = (os.environ.get("FB_PAGE_ID") or "").strip()

    if not app_id or not app_secret:
        print(
            "Pon en .env tu ID y clave secreta de la app de Facebook:\n"
            "  FB_APP_ID=tu_app_id\n"
            "  FB_APP_SECRET=tu_app_secret\n\n"
            "Los sacas de: developers.facebook.com → Tu app → Configuración → Básica.",
            file=sys.stderr,
        )
        return 1

    short_token = (os.environ.get("FB_TOKEN_CORTO") or "").strip()
    if not short_token and len(sys.argv) > 1:
        short_token = sys.argv[1].strip()
    if not short_token:
        print(
            "Token corto de usuario (del Explorador de la API Graph):\n"
            "  https://developers.facebook.com/tools/explorer/\n"
            "  Permisos: pages_show_list, pages_read_engagement, pages_manage_posts",
        )
        short_token = input("Pega el token y pulsa Enter: ").strip()
    if not short_token:
        print("Falta el token corto.", file=sys.stderr)
        return 1

    # 1) Canjear token corto por token de usuario de larga duración (60 días)
    url = (
        f"https://graph.facebook.com/{GRAPH_API_VERSION}/oauth/access_token"
        f"?grant_type=fb_exchange_token"
        f"&client_id={app_id}"
        f"&client_secret={app_secret}"
        f"&fb_exchange_token={short_token}"
    )
    try:
        r = requests.get(url, timeout=15)
        data = r.json()
    except requests.RequestException as e:
        print(f"Error al canjear token: {e}", file=sys.stderr)
        return 1

    if not r.ok:
        print(f"Error {r.status_code}: {data}", file=sys.stderr)
        return 1

    long_lived_user_token = (data.get("access_token") or "").strip()
    if not long_lived_user_token:
        print("No se obtuvo token de larga duración.", file=sys.stderr)
        return 1

    # 2) Obtener token de la página con el token de usuario largo
    r2 = requests.get(
        f"https://graph.facebook.com/{GRAPH_API_VERSION}/me/accounts",
        params={"access_token": long_lived_user_token, "fields": "id,name,access_token"},
        timeout=15,
    )
    if not r2.ok:
        print(f"Error me/accounts {r2.status_code}: {r2.json()}", file=sys.stderr)
        return 1

    accounts = r2.json().get("data") or []
    if not accounts:
        print("No se encontraron páginas. Revisa que el token tenga permisos pages_*.", file=sys.stderr)
        return 1

    # Buscar la página que coincida con FB_PAGE_ID, o usar la primera
    page_token = None
    page_name = None
    for item in accounts:
        if page_id and str(item.get("id")) == page_id:
            page_token = (item.get("access_token") or "").strip()
            page_name = item.get("name", "")
            break
    if not page_token and accounts:
        item = accounts[0]
        page_token = (item.get("access_token") or "").strip()
        page_name = item.get("name", "")
        page_id = str(item.get("id", ""))

    if not page_token:
        print("No se pudo obtener el token de la página.", file=sys.stderr)
        return 1

    print("\n" + "=" * 60)
    print("Token de la PÁGINA (no caduca / larga duración)")
    print("Cópialo en .env como FB_PAGE_ACCESS_TOKEN=")
    print("=" * 60)
    print(page_token)
    print("=" * 60)
    if page_name:
        print(f"Página: {page_name} (id={page_id})")
    print("\nListo. Pega ese valor en .env y ya no tendrás que renovar el token.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
