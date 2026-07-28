# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Descripción del proyecto

**SGO Tublood** — Sistema de Gestión de Observaciones para Tublood SA (empresa argentina de dispositivos médicos). Monolito Laravel 13 + Vue 3 + Inertia.js. Sin API REST separada; Inertia maneja toda la comunicación cliente-servidor mediante formularios y redirects estándar.

**Estado**: en desarrollo activo. Terminados: autenticación, CRUD de usuarios/roles (con sector, supervisor y gerente), carga masiva de usuarios por Excel, motor de alertas por vencimiento del plazo de gestión, portal público de carga de observaciones (sin login, con múltiples productos por observación en "Falla de Producto"), listado/edición admin de observaciones (modal que asigna responsable/sector, clasifica con prioridad/tipo de caso — al completar ambos una observación `pendiente_clasificacion` pasa a `clasificada` — y cambia el estado, con autorización por objeto), CRUD de clientes con campo propio de vencimiento y archivos adjuntos, campana de notificaciones de clientes por vencer, dashboard con estadísticas reales, integración de sincronización de clientes con RP Sistemas (ERP), tabla de sectores sembrada, y una librería de componentes Vue compartidos. Pendiente: clasificación (Garantía de Calidad), derivación entre sectores, SLA configurable por prioridad (hoy el plazo es fijo por sector), No Conformidades/CAPA, envío real de emails (el driver sigue en `log`), modo oscuro para el portal público. Ver [docs/ARQUITECTURA-SGO.md](docs/ARQUITECTURA-SGO.md) para reglas de negocio, modelo de datos completo y hoja de ruta por fases — es la fuente de verdad para partes del dominio aún no implementadas (p. ej. campos de "Disconformidad de Servicio", que dependen del `Especificacion-Tecnica-SGO-v3.docx`). `docs/Notas-Finales-Prototipo.md` es un addendum posterior con ajustes de reglas de negocio (p. ej. flujo de clasificación y de derivación entre sectores) que **prevalece sobre `ARQUITECTURA-SGO.md`** en caso de discrepancia.

## Comandos

```bash
# Configuración inicial
composer run setup          # instala deps, migra, seedea y compila assets

# Desarrollo diario
composer run dev            # concurrente: servidor Laravel + queue worker + logs Pail + Vite HMR
npm run dev                 # solo Vite HMR (sin servidor/queue/logs) — usar composer run dev salvo necesidad puntual

# Build y tests
npm run build                            # build de producción con Vite (NO chequea tipos)
npx vue-tsc --noEmit                     # chequeo de tipos: no está enganchado al build, correlo a mano
composer test                            # limpia config y corre PHPUnit (tests/Unit/ y tests/Feature/)
php artisan test --filter=NombreDelTest  # correr un solo test/clase
./vendor/bin/pint                        # linter PHP (Laravel Pint) — correlo sobre archivos tocados antes de terminar

# Alertas
php artisan observaciones:alertas   # avisa por observaciones vencidas y escala al gerente (agendado cada hora)

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
- `HandleInertiaRequests` comparte en cada request `notificaciones.vencimientos` (clientes vencidos o por vencer en ≤30 días, gateado por `can('clientes.view')` — la query solo corre si el usuario tiene el permiso, por cortocircuito del ternario) y `notificaciones.alertas` (`unreadNotifications` del usuario). Ambos alimentan la campana en `AppLayout.vue`.

**Frontend**
- Todos los componentes de página van en `resources/js/Pages/`, con el mismo nombre que se pasa a `inertia()` (incluye el subpath: `inertia('Admin/Observaciones/Index')` → `resources/js/Pages/Admin/Observaciones/Index.vue`).
- Componentes reutilizables (no-página) van en `resources/js/Components/`: `Badge`, `Button`, `Modal`, `Pagination`, los de formulario (`Input`, `Select`, `Textarea`, `RadioGroup`, `FormSection`) y dos de dominio (`CampoDinamico`, `CamposUsuario`). **Nunca reimplementar un input inline**: además de duplicar estilo, es así como `CrearInterna.vue` quedó sin modo oscuro cuando se agregó el resto. `Modal` acepta `size="sm"|"lg"|"xl"` y se cierra con click en el backdrop o evento `@close`.
- Los componentes de formulario comparten props: `label`, `error`, `hint` (ayuda debajo, la tapa el error), `required` (dibuja el `*`) y `full` (ocupa las dos columnas de un `FormSection`). `FormSection` da título + separador y acomoda los campos en 2 columnas por defecto (`:columns="1"` para una sola).
- `CampoDinamico` **no tiene estilo propio**: despacha a `Input`/`Select`/`Textarea`/`RadioGroup` según `campo.tipo`. `CamposUsuario` agrupa los campos que comparten el alta y la edición de usuarios (recibe el objeto de `useForm` tipado como `InertiaForm<UserFormData>` y lo muta directo) y solo se parametriza con `modo="crear"|"editar"`.
- Patrón habitual para editar una fila de una tabla sin navegar: un `Modal` controlado por un `ref` en la página `Index.vue` (ver `Admin/Observaciones/Index.vue`) en vez de una página `Edit.vue` separada — usar este patrón para ediciones simples (pocos campos) antes de crear una página dedicada. Cuando el formulario tiene muchos campos y/o subida de archivos (ver `Admin/Clientes/Edit.vue`, `Admin/Users/Edit.vue`), sí se justifica una página `Edit.vue` propia.
- En `Admin/Observaciones/Index.vue`, el botón "Editar" por fila se muestra según `puedeEditar(o)` (`isSuperAdmin || o.responsable_id === user.id`), **no** según un permiso global — refleja la autorización por objeto del backend (`ObservacionPolicy`).
- Alias de import `@/` apunta a `resources/js/`.
- TypeScript en modo strict. Los tipos compartidos están en [resources/js/types/index.d.ts](resources/js/types/index.d.ts) (`User`, `Role`, `Permission`, `PageProps`, `PaginatedData`, `Cliente`, `ClienteAttachment`, `Observacion`, `ObservationProduct`, `Sector`, `AlertaNotificacion`).
- Usar el composable `usePermissions()` ([resources/js/composables/usePermissions.ts](resources/js/composables/usePermissions.ts)) para checks de permisos en el cliente — expone `hasPermission`, `hasRole`, `isSuperAdmin` y `user` (con `id`, útil para comparar contra `responsable_id` en autorización por objeto). Nunca hardcodear nombres de roles en los componentes.
- Campana de notificaciones en [AppLayout.vue](resources/js/Layouts/AppLayout.vue): lee `page.props.notificaciones` (compartido por `HandleInertiaRequests`, no por un fetch aparte) y muestra un badge con la suma de dos grupos — `vencimientos` (clientes por vencer, calculado en vivo) y `alertas` (notificaciones no leídas de observaciones, del canal `database`). Solo las `alertas` se pueden marcar leídas (`notificaciones.leidas`).
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

### Sectores — una sola taxonomía organizacional
`sectors` es a la vez **dónde trabaja cada persona** (`users.sector_id`) y **a dónde se deriva una observación** (`observations.sector_id`), con `dias_gestion` (plazo del SLA en días hábiles) como dato propio. Hasta el 25/7/2026 existían dos tablas separadas (`sectors` y `areas`, esta última "creada sola" al importar el Excel); se unificaron porque los datos reales mostraban que eran la misma lista con distinta nomenclatura, y la separación bloqueaba filtrar "usuarios de tal sector" — prerrequisito de la clasificación exclusiva de Calidad y de la derivación entre sectores.

**El catálogo de sectores es fijo**: los 8 de `SectorSeeder` (Facturación, Logística, Depósito, Comercial, COMEX, Asuntos Regulatorios, Garantía de Calidad, Dirección Técnica), que además son la clave (`slug`) de `config/incidencias.php`. El import de Excel **no crea sectores nuevos**: el "sector original" de cada fila se resuelve contra ese catálogo (por slug directo, o vía `config('organizacion.alias_sectores')` para los nombres que no matchean — p. ej. `CALIDAD` → `garantia_calidad`, `VENTAS` → `comercial`). Un nombre que no matchea ningún sector del catálogo deja a ese usuario **sin sector** (con advertencia en el import, no aborta) — es el caso de `COMPRAS`, `FINANZAS` y `PRODUCCIÓN` del Excel actual.

⚠️ **El vencimiento sale del sector del *responsable*, no de la observación.** El reloj lo calcula `ObservacionObserver` con `$responsable->sector->dias_gestion`. `observations.sector_id` es un dato propio del caso (a qué sector se derivó, para reportes y para filtrar tipos de incidencia) y no mueve el SLA por sí solo — cambia si cambia el responsable, no al revés.

El **portal público deriva el sector del tipo**: el cliente elige el tipo y `TaxonomiaIncidencias::sectorDeTipo()` resuelve a cuál pertenece (los dos tipos externos están declarados bajo `garantia_calidad`). Si faltara el registro en `sectors`, queda en `null` y el reclamo se guarda igual — el portal es público y nunca puede perder un reclamo.

Al crear una observación externa, `Portal\ObservacionController::avisar()` manda dos avisos **fuera de la transacción**: acuse de recibo al cliente y aviso al equipo de Calidad. Los destinatarios internos son los usuarios activos del sector de la observación, con fallback al **rol** `garantia_calidad` si ese sector no tiene a nadie cargado (transición). Dos cosas no negociables ahí:
- Ese fallback busca con `whereHas('roles', ...)`, **no** con el scope `User::role(...)` de Spatie: ese scope **tira `RoleDoesNotExist`** si el rol no está creado, y eso sería un 500 en un endpoint público (lo agarró un test).
- Todo va dentro de un `try/catch` que loguea: para cuando corre, el reclamo ya está guardado, así que un fallo al avisar no puede devolverle un error al cliente y hacerle creer que no se cargó.

**Todavía pendiente**: derivación entre sectores.

### Estructura de usuarios y motor de alertas
- **Jerarquía** — `users` tiene `sector_id`, `supervisor_id` (a quién se escala), `gerente_id` (aviso final + último escalón) y `es_gerente`. `User::cadenaEscalamiento()` sube por `supervisor_id` con guarda de visitados; `User::generariaCiclo($id)` es el chequeo anti-círculo que usan **tanto** el formulario (`UserController::reglas()`) como el import.
- **Import de Excel** ([app/Services/UserImportService.php](app/Services/UserImportService.php)) — 7 columnas por posición (Nombre, Apellido, Mail, Sector original, Supervisor, Gerente, Tiempo de gestión), upsert por email. Va en **dos pasadas** porque el archivo referencia supervisores que aparecen en filas posteriores: la primera crea/actualiza usuarios y resuelve sectores, la segunda resuelve los nombres. `"si"` en la columna Supervisor marca a los gerentes. **Las columnas vacías no pisan lo cargado**, así que un archivo viejo de 3 columnas sigue funcionando. Los problemas (nombre inexistente/ambiguo, ciclo, plazos en conflicto, sector fuera del catálogo) salen como **advertencias** en el flash `error`, no abortan el import.
- **Reloj de gestión** — `observations.responsable_asignado_at` / `vence_at` / `alerta_nivel` los setea [app/Observers/ObservacionObserver.php](app/Observers/ObservacionObserver.php) (enganchado con `#[ObservedBy]`), **no** los controllers: el responsable se asigna desde tres lugares distintos y así hay un solo punto de verdad. Arranca al asignar responsable (no al crear), dura `sector.dias_gestion` **días hábiles** (`addWeekdays()`, sin feriados argentinos) y se reinicia al reasignar. Sin sector o sin plazo, `vence_at` queda `null` y esa observación nunca alerta.
- **Escalamiento** — `observaciones:alertas` (agendado `->hourly()`): nivel 0→1 avisa a responsable + supervisor; 1→2 escala al gerente + supervisor recién cuando pasó **otro** plazo igual. `alerta_nivel` lo hace idempotente. Al pasar a un estado de `config('incidencias.estados_finales')` (hoy solo `cerrada`) el observer manda el **aviso final** al gerente.
- **Notificaciones** — `app/Notifications/Observacion*Notification.php` heredan de `ObservacionNotification` (canales `database` + `mail`, e **implementan `ShouldQueue`**: cada mail es una llamada HTTP a Resend, así que no puede colgar la request). El canal `database` alimenta la campana vía `HandleInertiaRequests` → `notificaciones.alertas`. La única que **no** hereda de la base es `ObservacionRecibidaClienteNotification`: su destinatario es un mail suelto (`Notification::route('mail', ...)`), no un `User`, así que no tiene canal `database` ni `$notifiable->name`. ⚠️ **Los dos canales no van por el mismo lado**: `viaConnections()` manda `database` a la conexión `sync`, así que la fila se escribe en el acto (es un INSERT local), y solo `mail` queda encolado — que es la llamada HTTP a Resend y lo único que justifica la cola. Gracias a eso la campana y el modal de reclamos nuevos funcionan **aunque no haya un worker corriendo**; lo que sí se acumula sin worker son los mails. Con `QUEUE_CONNECTION=database` y la app servida por el vhost de Laragon no hay worker: hay que correr `php artisan queue:work` aparte (o `composer run dev`, que ya lo levanta), y en prod hace falta un supervisor/cron. Si alguna vez se agrega un canal nuevo, decidir explícitamente de qué lado va.
- **Reclamos sin clasificar** — para quien sea de Garantía de Calidad (rol `garantia_calidad` **o** sector homónimo — `User::esDeCalidad()`), dos capas independientes, ambas en `HandleInertiaRequests`:
  - **Campana → "Sin clasificar"** (`notificaciones.sinClasificar`): consulta viva `Observacion::where('estado', 'pendiente_clasificacion')`, no la tabla de notificaciones. Es la fuente de verdad — se autolimpia sola en cuanto alguien clasifica el caso (desde ahí mismo o desde el modal de edición del listado) y no puede quedar desincronizada ni apuntar a una observación borrada.
  - **Modal "Entró un reclamo nuevo"** (`notificaciones.externas`): el *subconjunto* de lo de arriba que este usuario todavía no vio — se calcula filtrando `sinClasificar` contra las `ObservacionExternaRecibidaNotification` sin leer, no con una consulta aparte, así el popup nunca puede ofrecer algo ya clasificado o inexistente. Se refresca con `usePoll(60000, { only: ['notificaciones'] })` en `AppLayout.vue`, así que puede aparecer en cualquier pantalla mientras la persona ya está trabajando: se abstiene si la pantalla es un formulario (`page.component` matchea `Crear|Create|Edit|Nuevo`) o si hay otro modal abierto (contador a nivel de módulo `modalesAbiertos` en `Components/Modal.vue`). Cerrarlo pega a `notificaciones.externas.vistas`, que solo marca vistos esos avisos — **no clasifica nada**, así que el reclamo sigue en la campana hasta que alguien haga el trabajo real.

  `alertas` (las de vencimiento/escalamiento) excluye el tipo `ObservacionExternaRecibidaNotification` para no duplicar lo que ya muestra "Sin clasificar", y por la misma razón `NotificacionController::marcarLeidas()` (el botón "Marcar leídas" de ese bloque) no toca esos avisos — son dos mecanismos independientes.

  Por eso `Portal\ObservacionController::avisar()` notifica a la **unión** de sector y rol, y no al sector con el rol de fallback: quien tiene el rol pero está cargado en otro sector también tiene que ver el aviso.
- **Sectores** — ABM mínimo en `Admin/Sectores/Index.vue` + `Admin\SectorController`, gateado con los permisos **`users.view`/`users.edit`** (no tiene permisos propios: es parte de la estructura de usuarios). Existe para corregir `dias_gestion` sin reimportar el Excel — **no** amplía el catálogo fijo de sectores (ver arriba). `SectorController::update()` **no recalcula el slug** al renombrar, porque es la clave con la que el import y `config/incidencias.php` reconocen el sector.

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

Los **sectores** no tienen permisos propios: reusan `users.view`/`users.edit`.

## Entorno

- **DB**: el `.env` de este entorno de desarrollo apunta a **MySQL** (`DB_CONNECTION=mysql`, DB `sgo-tublood`), no a SQLite pese a lo que sugeriría un setup default de Laravel — verificar `.env` antes de asumir. Los **tests** son la excepción: `phpunit.xml` fuerza `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` sin importar el `.env`.
- **Prod**: MySQL (configurar en `.env`). Hosting compartido (Hostinger); deploy con `./deploy.sh` corrido a mano por SSH — no hay CI/CD. Hace `git pull --ff-only` de la rama actual (hoy se despliega directo desde `feature/codigo-producto-y-espanol`, **no** desde `main`, que quedó desactualizada), migra, reseedea `RolesAndPermissionsSeeder` (agregar un permiso nuevo en el código no alcanza: sin este paso queda invisible en producción hasta que alguien lo corra a mano) y regenera cachés de config/rutas/vistas. No compila assets — `public/build/` viene versionado en el repo porque el hosting no tiene Node.
- **Queue**: driver database (sin Redis en dev)
- **Mail**: **Resend** (`resend/resend-php`; el mailer y `services.resend.key` ya venían en el scaffold de Laravel). En **dev queda en `MAIL_MAILER=log`** a propósito — los mails se leen en `storage/logs/laravel.log` y no hay riesgo de mandarle una prueba a un cliente real. Para activarlo: `MAIL_MAILER=resend` + `RESEND_API_KEY`, y `MAIL_FROM_ADDRESS` **tiene que ser de un dominio verificado en Resend** o rechaza el envío. Los tests nunca mandan nada: `phpunit.xml` fuerza `MAIL_MAILER=array`.
- **Alias Vite**: `@/` → `resources/js/`
- **Laravel Boost**: `laravel/boost` está instalado y expone un servidor MCP (`.mcp.json` → `php artisan boost:mcp`) con acceso a schema de DB, logs de error, `last-error` y búsqueda semántica de docs de Laravel. Útil para inspeccionar el estado real de la app sin escribir scripts de tinker.

## Testing

PHPUnit corre contra SQLite `:memory:` (forzado por `phpunit.xml`, independiente del `DB_CONNECTION` real del `.env`). Suites en `tests/Feature/`: `ClienteControllerTest`, `ClienteSyncTest`, `ObservacionPublicaTest`, `ObservacionAdminTest`, `DashboardTest`, `UserControllerTest`, `UserImportTest`, `AlertasObservacionTest`, `NotificacionesVencimientoTest`, más `tests/Unit/`. `UserImportTest` arma un `.xlsx` real con PhpSpreadsheet (un `UploadedFile::fake()` genérico no se puede leer); `AlertasObservacionTest` usa `travelTo()` con fechas fijas para que el cálculo de días hábiles sea determinista, y `Notification::fake()`. Patrón habitual en tests de permisos: helper privado `userWith(...$permissions)` que crea el/los `Permission` con `firstOrCreate` y se los asigna a un usuario de prueba (ver cualquier test de `Admin/*`). Para tests de autorización por objeto (ver `ObservacionAdminTest`), en vez de/además de `userWith(...)` hay que setear `responsable_id` al crear la `Observacion` (o asignar el rol `super-admin`) para que el usuario de prueba pase la Policy. Para correr un solo archivo: `php artisan test tests/Feature/MiTest.php`.

**Nota**: `tests/Feature/ExampleTest.php` (boilerplate de Laravel) falla porque `/` redirige a `/login` (302, no 200) — es preexistente y no relacionado con ningún feature; no darlo por señal de regresión real.

## Datos de demo

`database/seeders/DemoDataSeeder.php` crea 10 usuarios (`user-1`..`user-10`, password `password`, rol `usuario_interno`) y 10 observaciones de prueba (7 `falla_producto` con distinta cantidad de productos + 3 `disconformidad_servicio`), vinculadas a clientes **ya existentes** en la tabla `clientes` (falla si no hay ninguno — no inventa clientes). **No** está enganchado a `DatabaseSeeder`, se corre a demanda: `php artisan db:seed --class=DemoDataSeeder`.
