#!/usr/bin/env bash
#
# Corre un archivo PHP de CONSULTA contra producción, sin poder escribir en ella.
#
#   bash .claude/skills/prod-consulta/prod-php.sh consulta.php
#
# El archivo tiene acceso a Laravel entero (modelos, DB::connection('erp'), config) y escribe su
# resultado con echo. Lo envuelve envoltura.php: fakes de mails/avisos/colas y rollback al final.
#
# El acceso sale de dos variables que NO están en el repo (es público): SGO_SSH (usuario@host) y
# SGO_SSH_KEY. Van en .claude/settings.local.json, que no se versiona.
#
set -euo pipefail

ARCHIVO="${1:?uso: prod-php.sh <consulta.php>}"
[ -f "$ARCHIVO" ] || { echo "No existe $ARCHIVO" >&2; exit 1; }
: "${SGO_SSH:?Falta SGO_SSH (usuario@host del droplet). Va en .claude/settings.local.json → env.}"
KEY="${SGO_SSH_KEY:?Falta SGO_SSH_KEY (ruta de la clave privada). Va en .claude/settings.local.json → env.}"
DIR="$(cd "$(dirname "$0")" && pwd)"

# El rollback deshace la base, y los fakes frenan los avisos. Lo que NO cubren: archivos, comandos de
# Artisan (corren su propio código, p. ej. un sync que llama al ERP y reescribe tablas enteras) y DDL.
# Una escritura en el modelo también se lista: casi nunca es lo que se quería en una consulta.
PATRON='(->|::)(update|delete|forceDelete|save|insert|insertOrIgnore|upsert|truncate|increment|decrement|attach|detach|sync|syncRoles|assignRole|givePermissionTo|notify|restore)\(|Storage::|File::|Artisan::|DB::statement|DB::unprepared|file_put_contents|unlink\(|exec\(|shell_exec|passthru|system\('
if grep -nE "$PATRON" "$ARCHIVO" >&2; then
  echo "⚠️  Las líneas de arriba escriben. La base se deshace con rollback, pero archivos, Artisan y DDL no." >&2
  if [ "${PROD_CONSULTA_FORZAR:-}" != 1 ]; then
    echo "Abortado. Si es a propósito (p. ej. probar el rollback), correr con PROD_CONSULTA_FORZAR=1." >&2
    exit 2
  fi
fi

Q=$(base64 -w0 < "$ARCHIVO")
W=$(base64 -w0 < "$DIR/envoltura.php")

# Una sesión ssh larga se corta a los ~600 s y puede matar el proceso remoto: `timeout 540` corta
# antes, limpio. Para algo que tarde más, es un trabajo para nohup, no para una consulta.
ssh -i "$KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SGO_SSH" "
  set -e
  T=\$(mktemp -d /tmp/prod-consulta.XXXXXX)
  trap 'rm -rf \"\$T\"' EXIT
  echo '$Q' | base64 -d > \"\$T/q.php\"
  echo '$W' | base64 -d > \"\$T/w.php\"
  chmod 755 \"\$T\"; chmod 644 \"\$T\"/*.php
  cd /var/www/sgo
  timeout 540 sudo -u deploy php \"\$T/w.php\" \"\$T/q.php\"
"
