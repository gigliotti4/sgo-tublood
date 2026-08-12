# Guía del Repositorio

## Estructura del Proyecto y Organización

Esta es una aplicación Laravel 13 con frontend Inertia/Vue. El backend vive en `app/`, las rutas en `routes/`, la configuración en `config/`, las migraciones/factories/seeders en `database/` y los archivos de idioma en `lang/`. El frontend está en `resources/js`: componentes en `Components/`, páginas en `Pages/`, layouts en `Layouts/`, composables en `composables/` y tipos compartidos en `types/`. Los estilos parten de `resources/css/app.css`; los assets compilados se sirven desde `public/`. Las pruebas de feature están en `tests/Feature`. Las notas operativas y planes de despliegue están en `docs/` y en archivos raíz `PLAN-*.md`.

## Comandos de Desarrollo, Build y Pruebas

- `composer install` y `npm install`: instalan dependencias PHP y Node.
- `composer setup`: crea `.env`, genera la clave, migra, instala paquetes Node y compila assets.
- `composer dev`: ejecuta Laravel, cola, logs y Vite para desarrollo local.
- `npm run dev`: inicia solo el servidor Vite.
- `npm run build`: compila assets frontend para producción.
- `composer test` o `php artisan test`: limpia configuración y ejecuta PHPUnit.
- `vendor/bin/pint`: formatea PHP con el preset Laravel.

## Estilo de Código y Nombres

Respeta `.editorconfig`: UTF-8, finales LF, nueva línea final e indentación de cuatro espacios, excepto YAML, que usa dos espacios salvo archivos Compose. PHP sigue Laravel Pint (`pint.json`, excluye `lang/`). Usa namespaces PSR-4 bajo `App\`, `Database\Factories`, `Database\Seeders` y `Tests\`. Nombra componentes Vue en PascalCase, composables como `useSomething.ts` y pruebas con nombres descriptivos `*Test.php`.

## Guía de Pruebas

Agrega o actualiza pruebas en `tests/Feature` para comportamiento visible, autorización, importaciones, notificaciones y flujos de controladores. Prefiere pruebas enfocadas y nombradas según el módulo o comportamiento, por ejemplo `ObservacionBajaTest.php`. Ejecuta `composer test` antes de entregar cambios backend; ejecuta `npm run build` cuando cambien Vue, TypeScript, CSS o la configuración de Vite.

## Commits y Pull Requests

Los commits recientes usan Conventional Commits con scopes en español, por ejemplo `feat(observaciones): filtros en 2 filas` y `fix(observaciones): el modal de avisos insiste...`. Mantén mensajes imperativos, acotados y concisos. Los pull requests deben describir el cambio, indicar migraciones o cambios de entorno, enlazar issues o planes relacionados, incluir capturas si hay UI y listar pruebas o builds ejecutados.

## Seguridad y Configuración

No commitees secretos de `.env`, credenciales de producción, cachés generados ni contenido local de `storage/`. Usa `.env.example` para nuevas claves de configuración y documenta cambios que afecten despliegues en el runbook o plan correspondiente.
