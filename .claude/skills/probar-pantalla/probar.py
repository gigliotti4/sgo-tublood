"""Abre una pantalla del panel LOCAL como un usuario y devuelve lo que le llega del backend.

    python -I .claude/skills/probar-pantalla/probar.py /compras
    python -I .claude/skills/probar-pantalla/probar.py "/observaciones?estado=abierta" --como alguien@tublood.com
    python -I .claude/skills/probar-pantalla/probar.py /compras --rol usuario_interno      # usuario descartable
    python -I .claude/skills/probar-pantalla/probar.py /compras --prop dataset.depositos

Reemplaza el curl a mano con cookie-jar + XSRF que se repitió decenas de veces en las sesiones. Solo
GET y solo contra el vhost local: nunca contra producción (para eso está /prod-consulta).

Salida: status, componente de Inertia, URL final (detecta redirects a /login o 403), las claves de
props con su tamaño, y el JSON completo de props escrito en --out para leerlo con grep/Read.
"""

import argparse
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

sys.stdout.reconfigure(encoding='utf-8')  # la consola de Windows es cp1252 y revienta con "→" o "ñ"

BASE = os.environ.get('PROBAR_BASE', 'http://sgo-tublood.test')
PHP = os.environ.get('PHP', r'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe')
RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))

p = argparse.ArgumentParser()
p.add_argument('ruta', help='ruta con query, p. ej. /compras o "/articulos?sin_proveedor=1"')
p.add_argument('--como', default='admin@admin.com', help='email del usuario (password: --password)')
p.add_argument('--password', default=os.environ.get('PROBAR_PASSWORD', 'password'))
p.add_argument('--rol', help='crea/usa un usuario DESCARTABLE prueba.<rol>@sgo.test con solo esos roles (coma)')
p.add_argument('--prop', help='camino a una prop para imprimirla (puntos: dataset.groups.0)')
p.add_argument('--out', help='archivo donde dejar el JSON de props (default: temp)')
a = p.parse_args()

# Git Bash reescribe un argumento "/compras" como "C:/Program Files/Git/compras" (conversión de rutas
# de MSYS). Se deshace acá para que nadie tenga que acordarse de MSYS_NO_PATHCONV=1.
a.ruta = re.sub(r'^[A-Za-z]:[/\\].*?[/\\]Git(?=[/\\])', '', a.ruta).replace('\\', '/')
if not a.ruta.startswith('/'):
    a.ruta = '/' + a.ruta

if not BASE.startswith(('http://sgo-tublood.test', 'http://127.0.0.1', 'http://localhost')):
    sys.exit(f'PROBAR_BASE={BASE} no es local. Este skill es solo para el vhost de Laragon.')


def usuario_descartable(roles: str) -> str:
    """Los usuarios de la base local son copias de gente real de Tublood: nunca se les cambia la
    contraseña para entrar como ellos. Se crea uno de prueba con exactamente esos roles."""
    lista = [r.strip() for r in roles.split(',') if r.strip()]
    email = f"prueba.{'-'.join(lista)}@sgo.test"
    codigo = f"""<?php
require {json.dumps(os.path.join(RAIZ, 'vendor', 'autoload.php'))};
$app = require {json.dumps(os.path.join(RAIZ, 'bootstrap', 'app.php'))};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$u = App\\Models\\User::firstOrCreate(['email' => {json.dumps(email)}], ['name' => 'Prueba', 'apellido' => {json.dumps(','.join(lista))}, 'password' => {json.dumps(a.password)}]);
$u->syncRoles({json.dumps(lista)});
echo $u->email;
"""
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf-8') as f:
        f.write(codigo)
    try:
        r = subprocess.run([PHP, f.name], cwd=RAIZ, capture_output=True, text=True, timeout=60)
    finally:
        os.unlink(f.name)
    if r.returncode != 0:
        sys.exit('No se pudo crear el usuario descartable:\n' + r.stdout + r.stderr)
    return email


email = usuario_descartable(a.rol) if a.rol else a.como

jar = http.cookiejar.CookieJar()


class SinRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


abrir = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), SinRedirect()).open


def pedir(metodo, ruta, datos=None, headers=None):
    req = urllib.request.Request(BASE + ruta, data=datos, method=metodo, headers=headers or {})
    try:
        r = abrir(req, timeout=120)
        return r.status, dict(r.headers), r.read()
    except urllib.error.HTTPError as e:  # 3xx/4xx/5xx llegan acá por el SinRedirect
        return e.code, dict(e.headers), e.read()


def xsrf():
    for c in jar:
        if c.name == 'XSRF-TOKEN':
            return urllib.parse.unquote(c.value)
    return ''


pedir('GET', '/login')
status, h, _ = pedir('POST', '/login', json.dumps({'email': email, 'password': a.password}).encode(), {
    'Content-Type': 'application/json', 'Accept': 'text/html', 'X-XSRF-TOKEN': xsrf(),
})
destino = h.get('Location', '')
if status != 302 or destino.rstrip('/').endswith('/login'):
    sys.exit(f'Login fallido para {email} (status {status}, va a {destino or "-"}). '
             'Ojo: el login tiene rate limit por IP; si se probó mucho, esperar un minuto.')

status, h, cuerpo = pedir('GET', a.ruta, headers={'Accept': 'text/html'})
print(f'GET {a.ruta} como {email} → {status}')
if status in (301, 302, 303):
    print(f"  redirige a {h.get('Location')}  (¿sin permiso, o una validación que rebota?)")
    sys.exit(1)

html = cuerpo.decode('utf-8', 'replace')
if status >= 400:
    titulo = re.search(r'<title>(.*?)</title>', html, re.S)
    print(f"  {status} {titulo.group(1).strip() if titulo else ''}"
          + ('  ← sin permiso: revisar el `can:` de la ruta y los roles del usuario' if status == 403 else ''))
    sys.exit(1)
m = re.search(r'<script data-page="app" type="application/json">(.*?)</script>', html, re.S)
if not m:
    print('  No hay data-page en la respuesta (¿error de PHP? primeros 600 caracteres):')
    print('  ' + re.sub(r'\s+', ' ', html)[:600])
    sys.exit(1)

pagina = json.loads(m.group(1))
props = pagina.get('props', {})
print(f"  componente: {pagina.get('component')}   url: {pagina.get('url')}")

out = a.out or os.path.join(tempfile.gettempdir(), 'probar-pantalla.json')
with open(out, 'w', encoding='utf-8') as f:
    json.dump(props, f, ensure_ascii=False, indent=1)
print(f'  props completas en {out} ({os.path.getsize(out):,} bytes)')

# Las que HandleInertiaRequests manda en todas las páginas: no dicen nada de la pantalla probada.
COMPARTIDAS = {'auth', 'flash', 'errors', 'notificaciones', 'configuracion', 'incidencias', 'broadcasting'}
for k, v in props.items():
    if k in COMPARTIDAS:
        continue
    tam = len(json.dumps(v, ensure_ascii=False))
    forma = f'lista[{len(v)}]' if isinstance(v, list) else f'obj{{{len(v)}}}' if isinstance(v, dict) else repr(v)[:60]
    print(f'  · {k}: {forma}  ({tam:,} bytes)')

for k in ('flash', 'errors'):
    v = props.get(k)
    if v and any(v.values() if isinstance(v, dict) else [v]):
        print(f'  {k}: {json.dumps(v, ensure_ascii=False)[:400]}')

if a.prop:
    v = props
    for parte in a.prop.split('.'):
        try:
            v = v[int(parte)] if isinstance(v, list) else v[parte]
        except (KeyError, IndexError, ValueError):
            claves = list(v.keys())[:30] if isinstance(v, dict) else f'lista de {len(v)}'
            sys.exit(f'\n--prop {a.prop}: no existe "{parte}". Hay: {claves}')
    print(f'\n{a.prop} =')
    print(json.dumps(v, ensure_ascii=False, indent=1)[:4000])
