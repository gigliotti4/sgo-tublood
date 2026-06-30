# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Descripción del proyecto

**SGO Tublood** — Sistema de Gestión de Observaciones para Tublood SA (empresa argentina de dispositivos médicos). Monolito Laravel 13 + Vue 3 + Inertia.js. Sin API REST separada; Inertia maneja toda la comunicación cliente-servidor mediante formularios y redirects estándar.

**Estado**: ~10% completado. Están terminados: autenticación, CRUD de usuarios/roles y el shell del dashboard. Todo el negocio principal (observaciones, flujos de trabajo, notificaciones, integración ERP) está pendiente. Ver [ARQUITECTURA-SGO.md](ARQUITECTURA-SGO.md) y `Especificacion-Tecnica-SGO-v3.docx` para reglas de negocio, modelo de datos completo y hoja de ruta por fases.

## Comandos

```bash
# Configuración inicial
composer run setup          # instala deps, migra, seedea y compila assets

# Desarrollo diario
npm run dev                 # concurrente: servidor Laravel + queue worker + logs Pail + Vite HMR

# Build y tests
npm run build               # build de producción con Vite
composer test               # PHPUnit (tests/Unit/ y tests/Feature/)
./vendor/bin/pint           # linter PHP (Laravel Pint)

# Base de datos
php artisan migrate         # ejecutar migraciones pendientes
php artisan db:seed         # re-seedear roles/permisos/usuario admin
php artisan tinker          # REPL

# Caché
php artisan cache:clear     # también limpia la caché de permisos de Spatie
php artisan config:clear
```

Usuario semilla por defecto: `admin@admin.com` / `password` (rol: `super-admin`).

## Arquitectura

### Flujo de una request
```
HTTP → Route (middleware: can:permiso) → Controller (authorize) → Eloquent → DB
                                                  ↓
                                  inertia('NombrePagina', $props)
                                                  ↓
                             Componente Vue 3 (resources/js/Pages/)
```

### Convenciones clave

**Backend**
- Los controllers van en `app/Http/Controllers/Admin/` (admin) o `app/Http/Controllers/Auth/` (auth). Los nuevos features siguen el mismo patrón de namespacing.
- La autorización es doble: middleware `can:permiso` en la ruta y `$this->authorize()` dentro del controller.
- `super-admin` saltea todos los checks de Gate vía `Gate::before()` en [AppServiceProvider.php](app/Providers/AppServiceProvider.php).
- Siempre eager-load relaciones en los controllers (`->with('roles')`) para evitar consultas N+1.
- La caché de Spatie dura 24 h — llamar `app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()` cada vez que se muten roles o permisos.

**Frontend**
- Todos los componentes de página van en `resources/js/Pages/`, con el mismo nombre que se pasa a `inertia()`.
- Alias de import `@/` apunta a `resources/js/`.
- TypeScript en modo strict. Los tipos compartidos están en [resources/js/types/index.d.ts](resources/js/types/index.d.ts) (`User`, `Role`, `Permission`, `PageProps`, `PaginatedData`).
- Usar el composable `usePermissions()` ([resources/js/composables/usePermissions.ts](resources/js/composables/usePermissions.ts)) para checks de permisos en el cliente — nunca hardcodear nombres de roles en los componentes.
- El middleware `HandleInertiaRequests.php` comparte `auth.user` (con roles y permisos) y mensajes flash a todas las páginas vía datos compartidos de Inertia.
- Los íconos son paths SVG de Heroicons embebidos directamente en [AppLayout.vue](resources/js/Layouts/AppLayout.vue).

### Roles (definidos en ARQUITECTURA-SGO.md)
| Rol | Propósito |
|---|---|
| `super-admin` | Todos los permisos, saltea Gate |
| `admin` | Gestión de usuarios y roles |
| `viewer` | Solo lectura |
| `cliente_externo` | Solo portal externo (sin login) |
| `usuario_interno` | Rol operativo principal |
| `solo_lectura` | Lectura de todo |
| `garantia_calidad` | Clasificación de observaciones exclusiva |

### Permisos existentes (notación de punto)
`users.view`, `users.create`, `users.edit`, `users.delete`, `roles.view`, `roles.create`, `roles.edit`, `roles.delete`, `permissions.view`

## Entorno

- **DB dev**: SQLite (`database/database.sqlite`)
- **DB prod**: MySQL (configurar en `.env`)
- **Queue**: driver database (sin Redis en dev)
- **Mail**: driver log en dev (no envía emails reales)
- **Alias Vite**: `@/` → `resources/js/`

## Testing

PHPUnit corre contra SQLite `:memory:`. Suites: `tests/Feature/` y `tests/Unit/`. Actualmente no hay tests escritos — agregar junto a cada feature nuevo. Para correr un solo archivo: `./vendor/bin/phpunit tests/Feature/MiTest.php`.
