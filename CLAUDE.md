# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Descripción del proyecto

**SGO Tublood** — Sistema de Gestión de Observaciones para Tublood SA (empresa argentina de dispositivos médicos). Monolito Laravel 13 + Vue 3 + Inertia.js. Sin API REST separada; Inertia maneja toda la comunicación cliente-servidor mediante formularios y redirects estándar.

**Estado**: en desarrollo activo. Terminados: autenticación, CRUD de usuarios/roles (con sector opcional), portal público de carga de observaciones (sin login, con múltiples productos por observación en "Falla de Producto"), listado/edición admin de observaciones (modal que asigna responsable/sector, clasifica con prioridad/tipo de caso — al completar ambos una observación `pendiente_clasificacion` pasa a `clasificada` — y cambia el estado, con autorización por objeto), CRUD de clientes con campo propio de vencimiento y archivos adjuntos, campana de notificaciones de clientes por vencer, dashboard con estadísticas reales, integración de sincronización de clientes con RP Sistemas (ERP), tabla de sectores sembrada, y una librería de componentes Vue compartidos. Pendiente: clasificación (Garantía de Calidad), derivación entre sectores (la tabla `sectors` existe pero `observations` todavía no tiene `sector_id`), No Conformidades/CAPA, SLAs, notificaciones por email, modo oscuro para el portal público. Ver [docs/ARQUITECTURA-SGO.md](docs/ARQUITECTURA-SGO.md) para reglas de negocio, modelo de datos completo y hoja de ruta por fases — es la fuente de verdad para partes del dominio aún no implementadas (p. ej. campos de "Disconformidad de Servicio", que dependen del `Especificacion-Tecnica-SGO-v3.docx`). `docs/Notas-Finales-Prototipo.md` es un addendum posterior con ajustes de reglas de negocio (p. ej. flujo de clasificación y de derivación entre sectores) que **prevalece sobre `ARQUITECTURA-SGO.md`** en caso de discrepancia.

## Comandos

```bash
# Configuración inicial
composer run setup          # instala deps, migra, seedea y compila assets

# Desarrollo diario
composer run dev            # concurrente: servidor Laravel + queue worker + logs Pail + Vite HMR
npm run dev                 # solo Vite HMR (sin servidor/queue/logs) — usar composer run dev salvo necesidad puntual

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
- La autorización tiene **dos mecanismos** según el caso:
  - **Por permiso** (global): middleware `can:permiso` en la ruta + `$this->authorize('permiso')` en el controller. Es el default para casi todo (`users.*`, `roles.*`, `clientes.*`, `observaciones.view`).
  - **Por objeto** (Policy): cuando la autorización depende del registro puntual, no solo del rol. Único caso hoy: **editar una observación** solo lo puede hacer su `responsable` asignado (`observacion.responsable_id === user.id`) — ver [app/Policies/ObservacionPolicy.php](app/Policies/ObservacionPolicy.php), enganchada en la ruta con `->middleware('can:update,observacion')` y en el controller con `$this->authorize('update', $observacion)`. El permiso `observaciones.edit` sigue existiendo en el seeder pero **ya no gatea nada** — quedó de la época en que cualquiera con ese permiso podía editar cualquier observación.
  - Rutas de `Portal/` son públicas por diseño, sin `$this->authorize()`.
- `super-admin` saltea **todos** los checks de Gate (tanto permisos como Policies) vía `Gate::before()` en [AppServiceProvider.php](app/Providers/AppServiceProvider.php) — por eso `ObservacionPolicy` no necesita chequear el rol explícitamente, alcanza con comparar `responsable_id`.
- Siempre eager-load relaciones en los controllers (`->with('roles')`, `->with(['responsable:id,name', 'cliente:id,numero,razon_social,mail,telefono', 'productos'])`) para evitar N+1 — y **restringir columnas** en el eager-load cuando el modelo relacionado tiene campos sensibles (p. ej. `User` tiene `password`): usar `'responsable:id,name'`, nunca `'responsable'` a secas si el resultado va a props de Inertia.
- La caché de Spatie dura 24 h — llamar `app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()` cada vez que se muten roles o permisos (ya está en el seeder).
- Modelos con catálogos de valores (estados, orígenes) los declaran como `public const` en el propio modelo — ver `Observacion::ESTADOS` y `Observacion::ORIGENES` en [app/Models/Observacion.php](app/Models/Observacion.php). Reusar esas constantes en las reglas de validación (`'in:' . implode(',', array_keys(Observacion::ESTADOS))`) en vez de hardcodear la lista.
- **Patrón de tabla hija con archivos/ítems repetibles**: `ObservationAttachment`, `ObservationProduct` y `ClienteAttachment` siguen el mismo molde — `foreignId('...')->constrained(...)->cascadeOnDelete()`, `$fillable` plano, relación `belongsTo` de vuelta al padre y `hasMany` en el padre. Los adjuntos (`store('observaciones', 'local')` / `store('clientes', 'local')`) van al disco `local` (privado, `storage/app/private`). **Los adjuntos de observaciones son solo de escritura** (no hay ruta que los sirva); los de clientes sí tienen descarga (`ClienteController::downloadArchivo`, único download real del código — usa `Storage::disk('local')->download(...)`). Ojo con `scopeBindings()` en rutas anidadas: Laravel busca el método de relación por el **plural del nombre del parámetro de ruta** (por eso el parámetro se llama `{attachment}`, no `{archivo}`, para matchear `Cliente::attachments()`).
- **Sincronización de clientes** (`ClienteSyncService::sync()`) hace `upsert()` con `numero` como clave. Cualquier columna que se agregue a `clientes` para ser **editable a mano en el panel** (ej. `fecha_vencimiento`) **no debe** agregarse a la lista de columnas del `upsert()`, o la próxima sync (corre cada 5 min) la pisa con lo que traiga el ERP.
- `HandleInertiaRequests` comparte `notificaciones.vencimientos` (clientes vencidos o por vencer en ≤30 días) en cada request, gateado por `can('clientes.view')` — alimenta la campana en `AppLayout.vue`. La query solo corre si el usuario tiene el permiso (cortocircuito del operador ternario).

**Frontend**
- Todos los componentes de página van en `resources/js/Pages/`, con el mismo nombre que se pasa a `inertia()` (incluye el subpath: `inertia('Admin/Observaciones/Index')` → `resources/js/Pages/Admin/Observaciones/Index.vue`).
- Componentes reutilizables (no-página) van en `resources/js/Components/`: `Badge`, `Input`, `Select`, `Button`, `Modal`, `Pagination`. Preferir estos sobre reimplementar inputs/badges/paginación inline — están tipados con `defineModel` y soportan modo oscuro. `Modal` acepta `size="sm"|"lg"` y se cierra con click en el backdrop o evento `@close`.
- Patrón habitual para editar una fila de una tabla sin navegar: un `Modal` controlado por un `ref` en la página `Index.vue` (ver `Admin/Observaciones/Index.vue`) en vez de una página `Edit.vue` separada — usar este patrón para ediciones simples (pocos campos) antes de crear una página dedicada. Cuando el formulario tiene muchos campos y/o subida de archivos (ver `Admin/Clientes/Edit.vue`, `Admin/Users/Edit.vue`), sí se justifica una página `Edit.vue` propia.
- En `Admin/Observaciones/Index.vue`, el botón "Editar" por fila se muestra según `puedeEditar(o)` (`isSuperAdmin || o.responsable_id === user.id`), **no** según un permiso global — refleja la autorización por objeto del backend (`ObservacionPolicy`).
- Alias de import `@/` apunta a `resources/js/`.
- TypeScript en modo strict. Los tipos compartidos están en [resources/js/types/index.d.ts](resources/js/types/index.d.ts) (`User`, `Role`, `Permission`, `PageProps`, `PaginatedData`, `Cliente`, `ClienteAttachment`, `Observacion`, `ObservationProduct`, `Sector`).
- Usar el composable `usePermissions()` ([resources/js/composables/usePermissions.ts](resources/js/composables/usePermissions.ts)) para checks de permisos en el cliente — expone `hasPermission`, `hasRole`, `isSuperAdmin` y `user` (con `id`, útil para comparar contra `responsable_id` en autorización por objeto). Nunca hardcodear nombres de roles en los componentes.
- Campana de notificaciones en [AppLayout.vue](resources/js/Layouts/AppLayout.vue): lee `page.props.notificaciones.vencimientos` (compartido por `HandleInertiaRequests`, no por un fetch aparte) y muestra badge + dropdown de clientes por vencer.
- Modo oscuro: composable `useDarkMode()` ([resources/js/composables/useDarkMode.ts](resources/js/composables/useDarkMode.ts)), estado global vía `ref` a nivel de módulo + `localStorage['theme']`, clase `.dark` en `<html>` (Tailwind v4 configurado con `@custom-variant dark` en [resources/css/app.css](resources/css/app.css), no la variante por media-query por defecto). **Solo cubre el panel interno** (`AppLayout` + páginas `Admin/*` + `Dashboard`) — el login y el portal público (`Pages/Auth/Login.vue`, `Pages/Portal/*`) mantienen su diseño de marca fijo a propósito y no tienen clases `dark:`.
- El middleware `HandleInertiaRequests.php` comparte `auth.user` (con roles y permisos) y mensajes flash a todas las páginas vía datos compartidos de Inertia.
- Los íconos son paths SVG de Heroicons embebidos directamente en [AppLayout.vue](resources/js/Layouts/AppLayout.vue) (objeto `icons`).

### Integración RP Sistemas (ERP externo)
- `app/Services/RpSistemas/RpSistemasClient.php` — cliente HTTP de bajo nivel (`Http::baseUrl(...)->withToken(...)`), solo expone `getClientes()` paginado con filtros; **no** tiene lookup por un único cliente.
- `app/Services/RpSistemas/ClienteSyncService.php` — pagina todos los clientes del ERP y hace `upsert` en la tabla local `clientes` (clave `numero`), idempotente.
- Se dispara vía `SyncClientesJob` (cola), comando `php artisan clientes:sync` (con `--sync` para modo síncrono), o el botón "Sincronizar" en `Admin/Clientes/Index.vue`. Programado cada 5 minutos (`everyFiveMinutes()`) en `routes/console.php`.
- Cuando un `Observacion` se crea desde el portal público con un `contacto_numero_cliente`, `Portal\ObservacionController` busca ese número en la tabla local `clientes` (**no** llama al ERP en vivo) y setea `cliente_id` si hay match — ver flujo completo en [app/Http/Controllers/Portal/ObservacionController.php](app/Http/Controllers/Portal/ObservacionController.php).

### Productos por observación (Falla de Producto)
- Una observación de tipo `falla_producto` puede tener **varios productos**, cada uno con su propio `producto`, `cantidad_afectada`, `lote`, `fecha_vencimiento`, `numero_remito` y `tipo_comprobante` — tabla hija `observation_products` / modelo `ObservationProduct` / relación `Observacion::productos()`. Campos que siguen siendo **únicos por observación** (no por producto): `institucion`, `provincia`, `equipamiento`, `ejecutivo_cuenta`.
- El type `disconformidad_servicio` no usa productos.
- En el portal (`CargarObservacion.vue`) la sección de productos es una lista repetible (`form.productos: Producto[]`, botón "+ Agregar producto"); el controller valida con notación de array (`productos.*.campo`) y hace un loop de `$observacion->productos()->create($p)` dentro de la misma transacción que crea la observación.

### Sectores
- Tabla `sectors` (sembrada por `SectorSeeder` con los 8 sectores de `docs/ARQUITECTURA-SGO.md`: Facturación, Logística, Depósito, Comercial, COMEX, Asuntos Regulatorios, Garantía de Calidad, Dirección Técnica) + `users.sector_id` (FK nullable, asignable desde `Admin/Users/Create.vue`/`Edit.vue`).
- `observations.sector_id` (FK nullable → `sectors`, `nullOnDelete`) ya existe: lo setea la **carga manual** desde el panel (ver "Carga manual"). **Todavía pendiente**: derivación entre sectores y auto-asignación del portal público a Garantía de Calidad (sigue como TODO en `Portal\ObservacionController`).

### Carga manual de observaciones (panel)
- El botón "+ Nueva" del Dashboard lleva a `Admin/Observaciones/Nuevo.vue` (`observaciones.nuevo`), un selector de 3 tarjetas: **externa**, **interna** y **No Conformidad** (esta última todavía sin formulario). Ambos formularios están gateados por el permiso `observaciones.edit` (`create`/`store` en `Admin\ObservacionController`, rutas `observaciones.create`/`observaciones.store`).
- **Externa** (`Create.vue`): réplica del formulario del portal público (mismos campos + productos repetibles para `falla_producto`) más **Sector responsable** y **Responsable**. Estado inicial `pendiente_clasificacion`.
- **Interna** (`CrearInterna.vue`): formulario **dirigido por taxonomía**. El **Sector** elegido filtra el **Tipo de incidencia**, y cada tipo define su bloque variable **"Datos específicos"** (guardado en la columna JSON `observations.datos_especificos`). La taxonomía completa vive en **`config/incidencias.php`** (clave = slug del sector) — agregar un tipo nuevo es solo config, sin tocar código. `app/Support/TaxonomiaIncidencias.php` la lee y genera las **reglas de validación dinámicas** (`datos_especificos.<campo>`). Incluye clasificación en el alta (Prioridad, Tipo de caso; la Prioridad se sugiere según el Tipo de caso); estado inicial `clasificada`. La columna `tecnovigilancia` existe (la usa el Dashboard) pero **no** se carga desde este formulario. El componente `Components/CampoDinamico.vue` renderiza cada campo (text/textarea/number/date/select/radio) desde su def.
- `store()` en `Admin\ObservacionController` **bifurca por `origen`**: `interna` → `storeInterna()` (validación dinámica vía el helper); el resto → flujo externa. `create()` bifurca igual para devolver `Create` o `CrearInterna`.

### Roles (definidos en docs/ARQUITECTURA-SGO.md)
| Rol | Propósito |
|---|---|
| `super-admin` | Todos los permisos, saltea Gate |
| `admin` | Gestión de usuarios, roles, clientes y observaciones |
| `viewer` | Solo lectura (users, roles, clientes, observaciones) |
| `cliente_externo` | Solo portal externo (sin login) |
| `usuario_interno` | Rol operativo principal (clientes + observaciones) |
| `solo_lectura` | Lectura de clientes y observaciones |
| `garantia_calidad` | Ve y edita observaciones; clasificación (aún no implementada) será exclusiva de este rol |

Nota: tener el permiso `observaciones.edit` (roles `admin`, `usuario_interno`, `garantia_calidad`) ya **no** alcanza para editar cualquier observación — ver autorización por objeto (`ObservacionPolicy`) más arriba.

### Permisos existentes (notación de punto)
`users.view`, `users.create`, `users.edit`, `users.delete`, `roles.view`, `roles.create`, `roles.edit`, `roles.delete`, `permissions.view`, `clientes.view`, `clientes.sync`, `clientes.edit`, `observaciones.view`, `observaciones.edit`

## Entorno

- **DB**: el `.env` de este entorno de desarrollo apunta a **MySQL** (`DB_CONNECTION=mysql`, DB `sgo-tublood`), no a SQLite pese a lo que sugeriría un setup default de Laravel — verificar `.env` antes de asumir. Los **tests** son la excepción: `phpunit.xml` fuerza `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` sin importar el `.env`.
- **Prod**: MySQL (configurar en `.env`).
- **Queue**: driver database (sin Redis en dev)
- **Mail**: driver log en dev (no envía emails reales)
- **Alias Vite**: `@/` → `resources/js/`
- **Laravel Boost**: `laravel/boost` está instalado y expone un servidor MCP (`.mcp.json` → `php artisan boost:mcp`) con acceso a schema de DB, logs de error, `last-error` y búsqueda semántica de docs de Laravel. Útil para inspeccionar el estado real de la app sin escribir scripts de tinker.

## Testing

PHPUnit corre contra SQLite `:memory:` (forzado por `phpunit.xml`, independiente del `DB_CONNECTION` real del `.env`). Suites en `tests/Feature/`: `ClienteControllerTest`, `ClienteSyncTest`, `ObservacionPublicaTest`, `ObservacionAdminTest`, `DashboardTest`, `UserControllerTest`, `NotificacionesVencimientoTest`, más `tests/Unit/`. Patrón habitual en tests de permisos: helper privado `userWith(...$permissions)` que crea el/los `Permission` con `firstOrCreate` y se los asigna a un usuario de prueba (ver cualquier test de `Admin/*`). Para tests de autorización por objeto (ver `ObservacionAdminTest`), en vez de/además de `userWith(...)` hay que setear `responsable_id` al crear la `Observacion` (o asignar el rol `super-admin`) para que el usuario de prueba pase la Policy. Para correr un solo archivo: `php artisan test tests/Feature/MiTest.php`.

**Nota**: `tests/Feature/ExampleTest.php` (boilerplate de Laravel) falla porque `/` redirige a `/login` (302, no 200) — es preexistente y no relacionado con ningún feature; no darlo por señal de regresión real.

## Datos de demo

`database/seeders/DemoDataSeeder.php` crea 10 usuarios (`user-1`..`user-10`, password `password`, rol `usuario_interno`) y 10 observaciones de prueba (7 `falla_producto` con distinta cantidad de productos + 3 `disconformidad_servicio`), vinculadas a clientes **ya existentes** en la tabla `clientes` (falla si no hay ninguno — no inventa clientes). **No** está enganchado a `DatabaseSeeder`, se corre a demanda: `php artisan db:seed --class=DemoDataSeeder`.
