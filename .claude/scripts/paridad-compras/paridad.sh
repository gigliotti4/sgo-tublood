#!/usr/bin/env bash
#
# Compara las dos implementaciones del cálculo de Compras sobre el dataset REAL de la base local:
# resources/js/lib/compras.ts (el tablero) y app/Services/Compras/CalculoReposicion.php (el mail).
#
#   bash .claude/scripts/paridad-compras/paridad.sh [carpeta-de-salida]
#
# Termina en "PARIDAD OK" (exit 0) o lista las diferencias (exit 1). Los escenarios están en
# escenarios.json: un filtro o un campo nuevo necesita su escenario ACÁ, o la comparación no lo mira.
#
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../../.." && pwd)"
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-$(mktemp -d)}"
PHP="${PHP:-/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe}"
mkdir -p "$OUT"
cd "$RAIZ"

"$RAIZ/node_modules/.bin/esbuild" resources/js/lib/compras.ts --bundle --format=esm --platform=node \
  --log-level=warning --outfile="$OUT/compras.mjs"

"$PHP" "$RAIZ/.claude/scripts/run.php" "$DIR/dataset_y_php.php" "$OUT"

node "$DIR/comparar.mjs" "$OUT"
