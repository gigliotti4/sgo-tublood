---
name: verificar
description: El loop de verificación completo antes de dar un cambio por terminado o de commitear — Pint sobre lo tocado, tests relacionados y la suite entera, vue-tsc y build si hubo frontend. Reporta qué pasó, qué falló con el output, y qué no se verificó.
allowed-tools: Bash, Read, Grep, Glob
---

# Verificar un cambio

Se corre **siempre** antes de decir "listo" o de commitear. Un paso que no se pudo correr se dice; no
se da por bueno en silencio.

`php` no está en el PATH: usar `PHP=/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe` (la versión
que pide `composer.json`; hay otras más viejas en `C:\laragon\bin\php\` que no sirven).

## 1. Qué se tocó

```bash
git status --porcelain
git diff --name-only HEAD; git ls-files --others --exclude-standard
```

Si el pedido es verificar commits ya hechos (p. ej. antes de un deploy), usar el rango
`git diff --name-only <base>..HEAD`.

## 2. Pint sobre los `.php` tocados

```bash
$PHP vendor/bin/pint <archivos.php>
```

Solo los tocados, no el repo entero: un Pint global ensucia el diff con archivos ajenos al cambio. Si
Pint reformateó algo, decirlo (queda como parte del cambio).

## 3. Tests

1. **Primero los relacionados**, que dan respuesta en segundos: por cada clase tocada,
   `grep -rl "NombreDeClase" tests/`, más el test homónimo si existe
   (`app/Services/Compras/CalculoReposicion.php` → `tests/Unit/CalculoReposicionTest.php`).
   `$PHP artisan test tests/Feature/XTest.php tests/Unit/YTest.php`
2. **Después la suite entera**: `$PHP artisan config:clear && $PHP artisan test` (es lo que hace
   `composer test`).

- `tests/Feature/ExampleTest.php` **falla desde siempre** (`/` redirige a `/login`): reportarlo como
  falla conocida, nunca como regresión.
- Los tests corren contra SQLite en memoria (`phpunit.xml`), no contra el MySQL del `.env`. Lo que
  SQLite no agarra, no lo agarran los tests: columnas ambiguas en un join, `TIMESTAMPDIFF`, la
  colación `utf8mb4_bin`. Si el cambio toca SQL crudo, decirlo como no verificado o probarlo contra
  MySQL local con `/probar-pantalla`.
- Si un test falla, mostrar el output del fallo (no un resumen) y no seguir como si nada.

## 4. Frontend (si se tocó algún `.vue` o `.ts`)

```bash
npx vue-tsc --noEmit
npm run build
```

`npm run build` **no chequea tipos**: los dos van. El build además es lo que hace falta para que el
vhost de Laragon muestre el cambio.

## 5. Si se tocaron las fórmulas de Compras

Un cambio en `resources/js/lib/compras.ts` o en `app/Services/Compras/CalculoReposicion.php` necesita
además el agente **`paridad-compras`**: los tests unitarios no alcanzan para garantizar que las dos
copias den lo mismo sobre el dataset real.

## 6. Reporte

Una tabla corta: paso → resultado (✅ / ❌ con el output / ⏭️ no aplicaba). Y una línea con lo que
**no** se verificó (p. ej. "no se probó en el navegador", "el SQL crudo no corre en SQLite").
