#!/usr/bin/env bash
#
# Carga la API key de Resend en el .env y deja los mails andando.
#
#   sudo /var/www/sgo/deploy/configurar-resend.sh
#
# Existe por dos motivos:
#
#   1. `deploy.sh` corre `config:cache`, así que editar el .env a mano **no
#      alcanza**: hasta regenerar la caché, Laravel sigue leyendo el valor
#      viejo. Es el error que hace perder media hora preguntándose por qué no
#      sale ningún mail.
#   2. La key se pide sin eco, así que no queda en el historial del shell.
#
set -euo pipefail

APP_DIR=/var/www/sgo
ENV="${APP_DIR}/.env"

[ -f "$ENV" ] || { echo "No existe ${ENV}"; exit 1; }

leer_env() { grep -E "^${1}=" "$ENV" | head -1 | cut -d= -f2- | tr -d '"'; }

# `sed` con | como separador: las keys no lo usan, las barras sí podrían.
poner_env() {
    if grep -qE "^${1}=" "$ENV"; then
        sed -i "s|^${1}=.*|${1}=${2}|" "$ENV"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV"
    fi
}

echo "Dejá vacío para conservar el valor actual."
echo

read -rsp "RESEND_API_KEY: " KEY; echo
if [ -n "$KEY" ]; then
    poner_env RESEND_API_KEY "$KEY"
    echo "  ✓ key cargada (${#KEY} caracteres)"
fi

actual_from=$(leer_env MAIL_FROM_ADDRESS)
read -rp "MAIL_FROM_ADDRESS [${actual_from}]: " FROM
if [ -n "$FROM" ]; then
    poner_env MAIL_FROM_ADDRESS "\"${FROM}\""
    echo "  ✓ remitente: ${FROM}"
fi

poner_env MAIL_MAILER resend

echo
echo "==> Regenerando la caché de configuración"
cd "$APP_DIR"
sudo -u deploy php artisan config:clear >/dev/null
sudo -u deploy php artisan config:cache >/dev/null

# El worker tiene la config vieja en memoria: los mails salen encolados, así que
# sin esto seguiría usando el mailer anterior.
sudo -u deploy php artisan queue:restart >/dev/null
echo "  ✓ caché regenerada y worker reiniciado"

echo
read -rp "¿Mandar un mail de prueba? Dirección (vacío para saltear): " DESTINO
if [ -n "$DESTINO" ]; then
    sudo -u deploy php -r '
        require "/var/www/sgo/vendor/autoload.php";
        $app = require_once "/var/www/sgo/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        try {
            Illuminate\Support\Facades\Mail::raw(
                "Prueba de SGO Tublood. Si te llegó esto, Resend está andando.",
                fn ($m) => $m->to($argv[1])->subject("SGO Tublood — prueba de envío")
            );
            echo "  ✓ enviado a {$argv[1]}\n";
        } catch (Throwable $e) {
            echo "  ✗ fallo: ".$e->getMessage()."\n";
            exit(1);
        }
    ' "$DESTINO"
fi

echo
echo "Listo. Si el envío falló, lo más probable es que el dominio de"
echo "MAIL_FROM_ADDRESS no esté verificado en Resend: es el requisito que"
echo "Resend exige antes de aceptar cualquier envío."
