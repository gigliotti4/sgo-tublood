# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Descripción del proyecto

**SGO Tublood** — Sistema de Gestión de Observaciones para Tublood SA (empresa argentina de dispositivos médicos). Monolito Laravel 13 + Vue 3 + Inertia.js. Sin API REST separada; Inertia maneja toda la comunicación cliente-servidor mediante formularios y redirects estándar.

**Estado**: en desarrollo activo. Terminados: autenticación, CRUD de usuarios/roles, portal público de carga de observaciones (sin login), listado/edición admin de observaciones (asignación de responsable y cambio de estado vía modal), dashboard con estadísticas reales, integración de sincronización de clientes con RP Sistemas (ERP), y una librería de componentes Vue compartidos. Pendiente: clasificación (Garantía de Calidad), derivación entre sectores, No Conformidades/CAPA, SLAs, notificaciones, modo oscuro para el portal público. Ver [ARQUITECTURA-SGO.md](ARQUITECTURA-SGO.md) para reglas de negocio, modelo de datos completo y hoja de ruta por fases — es la fuente de verdad para partes del dominio aún no implementadas (p. ej. campos de "Disconformidad de Servicio", que dependen del `Especificacion-Tecnica-SGO-v3.docx`).

## Comandos

```bash
# Configuración inicial
composer run setup          # instala deps, migra, seedea y compila assets

# Desarrollo diario
npm run dev                 # concurrente: servidor Laravel + queue worker + logs Pail + Vite HMR

# Build y tests
npm run build                            # build de producción con Vite
composer test                            # limpia config y corre PHPUnit (tests/Unit/ y tests/Feature/)
php artisan test --filter=NombreDelTest  # correr un solo test/clase
./vendor/bin/pint                        # linter PHP (Laravel Pint) — correlo sobre archivos tocados antes de terminar

# Base de datos
php artisan migrate         # ejecutar migraciones pendientes
php artisan db:seed         # re-seedear roles/permisos/usuario admin (RolesAndPermissionsSeeder)
php artisan tinker          # REPL

# Caché
php artisan cache:clear     # también limpia la caché de permisos de Spatie
php artisan config:clear
```

Usuario semilla por defecto: `admin@admin.com` / `password` (rol: `super-admin`).

## Arquitectura

### Flujo de una request (área admin, autenticada)
```
HTTP → Route (middleware: auth, can:permiso) → Controller (authorize) → Eloquent → DB
                                                        ↓
                                        inertia('NombrePagina', $props)
                                                        ↓
                                   Componente Vue 3 (resources/js/Pages/)
```

### Namespacing de controllers
- `app/Http/Controllers/Admin/` — CRUD administrativo autenticado (Users, Roles, Clientes, Observaciones).
- `app/Http/Controllers/Auth/` — login/logout.
- `app/Http/Controllers/Portal/` — rutas **públicas sin autenticación** (portal externo de carga de observaciones). No llevan `$this->authorize()`.
- `app/Http/Controllers/DashboardController.php` — vive suelto en `Controllers/` (no encaja en Admin ni Auth), calcula estadísticas para el panel principal.
- Cuando dos controllers en distinto namespace comparten nombre de clase (p. ej. `Portal\ObservacionController` y `Admin\ObservacionController`), en `routes/web.php` se importa uno con alias (`as AdminObservacionController`).

### Convenciones clave

**Backend**
- La autorización es doble: middleware `can:permiso` en la ruta y `$this->authorize()` dentro del controller (excepto rutas de `Portal/`, que son públicas por diseño).
- `super-admin` saltea todos los checks de Gate vía `Gate::before()` en [AppServiceProvider.php](app/Providers/AppServiceProvider.php).
- Siempre eager-load relaciones en los controllers (`->with('roles')`, `->with(['responsable:id,name', 'cliente:id,numero,razon_social,mail,telefono'])`) para evitar N+1 — y **restringir columnas** en el eager-load cuando el modelo relacionado tiene campos sensibles (p. ej. `User` tiene `password`): usar `'responsable:id,name'`, nunca `'responsable'` a secas si el resultado va a props de Inertia.
- La caché de Spatie dura 24 h — llamar `app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()` cada vez que se muten roles o permisos (ya está en el seeder).
- Modelos con catálogos de valores (estados, orígenes) los declaran como `public const` en el propio modelo — ver `Observacion::ESTADOS` y `Observacion::ORIGENES` en [app/Models/Observacion.php](app/Models/Observacion.php). Reusar esas constantes en las reglas de validación (`'in:' . implode(',', array_keys(Observacion::ESTADOS))`) en vez de hardcodear la lista.
- Adjuntos de archivos (`ObservationAttachment`) se guardan en el disco `local` (privado, no público) vía `$file->store('observaciones', 'local')`.

**Frontend**
- Todos los componentes de página van en `resources/js/Pages/`, con el mismo nombre que se pasa a `inertia()` (incluye el subpath: `inertia('Admin/Observaciones/Index')` → `resources/js/Pages/Admin/Observaciones/Index.vue`).
- Componentes reutilizables (no-página) van en `resources/js/Components/`: `Badge`, `Input`, `Select`, `Button`, `Modal`, `Pagination`. Preferir estos sobre reimplementar inputs/badges/paginación inline — están tipados con `defineModel` y soportan modo oscuro. `Modal` acepta `size="sm"|"lg"` y se cierra con click en el backdrop o evento `@close`.
- Patrón habitual para editar una fila de una tabla sin navegar: un `Modal` controlado por un `ref` en la página `Index.vue` (ver `Admin/Observaciones/Index.vue`) en vez de una página `Edit.vue` separada — usar este patrón para ediciones simples (pocos campos) antes de crear una página dedicada.
- Alias de import `@/` apunta a `resources/js/`.
- TypeScript en modo strict. Los tipos compartidos están en [resources/js/types/index.d.ts](resources/js/types/index.d.ts) (`User`, `Role`, `Permission`, `PageProps`, `PaginatedData`, `Cliente`, `Observacion`).
- Usar el composable `usePermissions()` ([resources/js/composables/usePermissions.ts](resources/js/composables/usePermissions.ts)) para checks de permisos en el cliente — nunca hardcodear nombres de roles en los componentes.
- Modo oscuro: composable `useDarkMode()` ([resources/js/composables/useDarkMode.ts](resources/js/composables/useDarkMode.ts)), estado global vía `ref` a nivel de módulo + `localStorage['theme']`, clase `.dark` en `<html>` (Tailwind v4 configurado con `@custom-variant dark` en [resources/css/app.css](resources/css/app.css), no la variante por media-query por defecto). **Solo cubre el panel interno** (`AppLayout` + páginas `Admin/*` + `Dashboard`) — el login y el portal público (`Pages/Auth/Login.vue`, `Pages/Portal/*`) mantienen su diseño de marca fijo a propósito y no tienen clases `dark:`.
- El middleware `HandleInertiaRequests.php` comparte `auth.user` (con roles y permisos) y mensajes flash a todas las páginas vía datos compartidos de Inertia.
- Los íconos son paths SVG de Heroicons embebidos directamente en [AppLayout.vue](resources/js/Layouts/AppLayout.vue) (objeto `icons`).

### Integración RP Sistemas (ERP externo)
- `app/Services/RpSistemas/RpSistemasClient.php` — cliente HTTP de bajo nivel (`Http::baseUrl(...)->withToken(...)`), solo expone `getClientes()` paginado con filtros; **no** tiene lookup por un único cliente.
- `app/Services/RpSistemas/ClienteSyncService.php` — pagina todos los clientes del ERP y hace `upsert` en la tabla local `clientes` (clave `numero`), idempotente.
- Se dispara vía `SyncClientesJob` (cola), comando `php artisan clientes:sync` (con `--sync` para modo síncrono), o el botón "Sincronizar" en `Admin/Clientes/Index.vue`. Programado diariamente a las 03:00 en `routes/console.php`.
- Cuando un `Observacion` se crea desde el portal público con un `contacto_numero_cliente`, `Portal\ObservacionController` busca ese número en la tabla local `clientes` (**no** llama al ERP en vivo) y setea `cliente_id` si hay match — ver flujo completo en [app/Http/Controllers/Portal/ObservacionController.php](app/Http/Controllers/Portal/ObservacionController.php).

### Roles (definidos en ARQUITECTURA-SGO.md)
| Rol | Propósito |
|---|---|
| `super-admin` | Todos los permisos, saltea Gate |
| `admin` | Gestión de usuarios, roles, clientes y observaciones |
| `viewer` | Solo lectura (users, roles, clientes, observaciones) |
| `cliente_externo` | Solo portal externo (sin login) |
| `usuario_interno` | Rol operativo principal (clientes + observaciones) |
| `solo_lectura` | Lectura de clientes y observaciones |
| `garantia_calidad` | Ve y edita observaciones; clasificación (aún no implementada) será exclusiva de este rol |

### Permisos existentes (notación de punto)
`users.view`, `users.create`, `users.edit`, `users.delete`, `roles.view`, `roles.create`, `roles.edit`, `roles.delete`, `permissions.view`, `clientes.view`, `clientes.sync`, `observaciones.view`, `observaciones.edit`

## Entorno

- **DB dev**: SQLite (`database/database.sqlite`)
- **DB prod**: MySQL (configurar en `.env`)
- **Queue**: driver database (sin Redis en dev)
- **Mail**: driver log en dev (no envía emails reales)
- **Alias Vite**: `@/` → `resources/js/`

## Testing

PHPUnit corre contra SQLite `:memory:`. Suites: `tests/Feature/` (`ClienteControllerTest`, `ClienteSyncTest`, `ObservacionPublicaTest`, `ObservacionAdminTest`, `DashboardTest`) y `tests/Unit/`. Patrón habitual en tests de permisos: helper privado `userWith(...$permissions)` que crea el/los `Permission` con `firstOrCreate` y se los asigna a un usuario de prueba (ver cualquier test de `Admin/*`). Para correr un solo archivo: `php artisan test tests/Feature/MiTest.php`.
