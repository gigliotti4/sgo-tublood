<?php

use App\Http\Controllers\Admin\ArticuloController as AdminArticuloController;
use App\Http\Controllers\Admin\BajaController;
use App\Http\Controllers\Admin\BitacoraController;
use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\Admin\ComprasController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\NoConformidadController;
use App\Http\Controllers\Admin\ObservacionController as AdminObservacionController;
use App\Http\Controllers\Admin\PartidaController;
use App\Http\Controllers\Admin\ProveedorController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VentaController;
use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\Portal\ObservacionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    // Nombres de ruta fijos: Illuminate\Auth\Notifications\ResetPassword y el
    // password broker asumen password.reset / password.request.
    Route::get('/recuperar-password', [PasswordResetController::class, 'solicitar'])->name('password.request');
    // Throttle propio (no el default del broker, que es por mail): los ~30
    // usuarios salen por una sola IP corporativa, mismo motivo que el login.
    Route::post('/recuperar-password', [PasswordResetController::class, 'enviarLink'])
        ->middleware('throttle:10,1')->name('password.email');
    Route::get('/restablecer-password/{token}', [PasswordResetController::class, 'formulario'])->name('password.reset');
    Route::post('/restablecer-password', [PasswordResetController::class, 'restablecer'])->name('password.update');
});

// Búsqueda de artículos del selector de productos. Pública porque el portal de
// carga no tiene login; devuelve solo código y descripción, con rate limit.
// La usan tanto el portal como la carga interna del panel.
Route::get('/articulos/buscar', [ArticuloController::class, 'buscar'])
    ->middleware('throttle:60,1')
    ->name('articulos.buscar');

Route::controller(ObservacionController::class)->group(function () {
    Route::get('/cargar-observacion', 'create')->name('observaciones.public.create');
    Route::post('/cargar-observacion', 'store')->name('observaciones.public.store');
    Route::get('/observacion-enviada', 'confirmacion')->name('observaciones.public.confirmacion');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/notificaciones/leidas', [NotificacionController::class, 'marcarLeidas'])
        ->name('notificaciones.leidas');

    // Usuarios
    Route::middleware('can:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });
    Route::middleware('can:users.create')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/import', [UserController::class, 'import'])->name('users.import');
    });
    Route::middleware('can:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
    Route::middleware('can:users.delete')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Sectores: parte de la estructura de usuarios, así que van con los mismos
    // permisos en vez de un permiso propio.
    Route::middleware('can:users.view')->group(function () {
        Route::get('/sectores', [SectorController::class, 'index'])->name('sectores.index');
    });
    Route::middleware('can:users.edit')->group(function () {
        Route::post('/sectores', [SectorController::class, 'store'])->name('sectores.store');
        Route::put('/sectores/{sector}', [SectorController::class, 'update'])->name('sectores.update');
    });

    // Clientes
    Route::middleware('can:clientes.view')->group(function () {
        // Antes del index para que /clientes/export ni /clientes/buscar caigan
        // en el listado (que espera un {cliente} numérico en otras rutas).
        Route::get('/clientes/export', [ClienteController::class, 'export'])->name('clientes.export');
        // Autocompletado de razón social por N° de cliente, usado en la carga
        // interna de observaciones (Admin/Observaciones/CrearInterna.vue).
        Route::get('/clientes/buscar', [ClienteController::class, 'buscarPorNumero'])->name('clientes.buscar');
        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/{cliente}/archivos/{attachment}', [ClienteController::class, 'downloadArchivo'])
            ->name('clientes.archivos.download')->scopeBindings();
    });
    Route::middleware('can:clientes.sync')->group(function () {
        Route::post('/clientes/sync', [ClienteController::class, 'sync'])->name('clientes.sync');
    });
    // Antes de /clientes/{cliente} para que no haya ambigüedad.
    Route::middleware('can:clientes.import')->group(function () {
        Route::post('/clientes/import', [ClienteController::class, 'import'])->name('clientes.import');
    });
    Route::middleware('can:clientes.edit')->group(function () {
        Route::get('/clientes/{cliente}/edit', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
        Route::post('/clientes/{cliente}/archivos', [ClienteController::class, 'uploadArchivo'])
            ->name('clientes.archivos.store');
        Route::delete('/clientes/{cliente}/archivos/{attachment}', [ClienteController::class, 'destroyArchivo'])
            ->name('clientes.archivos.destroy')->scopeBindings();
    });

    // Artículos (panel admin). El buscador público de arriba (/articulos/buscar)
    // se registra antes de este grupo, así que siempre matchea primero y no
    // choca con /articulos/{articulo}.
    Route::middleware('can:articulos.view')->group(function () {
        // Antes del index para que /articulos/export no caiga en el listado.
        Route::get('/articulos/export', [AdminArticuloController::class, 'export'])->name('articulos.export');
        Route::get('/articulos', [AdminArticuloController::class, 'index'])->name('articulos.index');
    });
    Route::middleware('can:articulos.sync')->group(function () {
        Route::post('/articulos/sync', [AdminArticuloController::class, 'sync'])->name('articulos.sync');
    });
    Route::middleware('can:articulos.import')->group(function () {
        Route::post('/articulos/import', [AdminArticuloController::class, 'import'])->name('articulos.import');
    });
    Route::middleware('can:articulos.edit')->group(function () {
        Route::get('/articulos/{articulo}/edit', [AdminArticuloController::class, 'edit'])->name('articulos.edit');
        Route::put('/articulos/{articulo}', [AdminArticuloController::class, 'update'])->name('articulos.update');
    });

    // Proveedores. Padrón cargado por Excel: no hay sync con RP Sistemas.
    Route::middleware('can:proveedores.view')->group(function () {
        // Antes de /proveedores/{proveedor}/edit y del index paginado: es el
        // autocompletado del selector de proveedor de un artículo.
        Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar'])->name('proveedores.buscar');
        Route::get('/proveedores/export', [ProveedorController::class, 'export'])->name('proveedores.export');
        Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
    });
    // Antes de /proveedores/{proveedor}/edit para que no haya ambigüedad.
    Route::middleware('can:proveedores.import')->group(function () {
        Route::post('/proveedores/import', [ProveedorController::class, 'import'])->name('proveedores.import');
    });
    Route::middleware('can:proveedores.sync')->group(function () {
        Route::post('/proveedores/sync', [ProveedorController::class, 'sync'])->name('proveedores.sync');
    });
    Route::middleware('can:proveedores.edit')->group(function () {
        Route::get('/proveedores/{proveedor}/edit', [ProveedorController::class, 'edit'])->name('proveedores.edit');
        Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
    });

    // Ventas. Espejo de solo lectura de la vista SQL del ERP: no hay alta,
    // edición ni borrado, la tabla se reemplaza entera en cada sincronización.
    Route::middleware('can:ventas.view')->group(function () {
        Route::get('/ventas', [VentaController::class, 'index'])->name('ventas.index');
    });
    Route::middleware('can:ventas.sync')->group(function () {
        Route::post('/ventas/sync', [VentaController::class, 'sync'])->name('ventas.sync');
    });

    // Partidas (lotes). Espejo de solo lectura de COMPRO_PARTIDAS del ERP,
    // agregado a una fila por (artículo, partida). Mismo contrato que Ventas:
    // no hay alta, edición ni borrado, la tabla se reemplaza en cada sync.
    Route::middleware('can:partidas.view')->group(function () {
        Route::get('/partidas', [PartidaController::class, 'index'])->name('partidas.index');
        Route::get('/partidas/{partida}', [PartidaController::class, 'show'])->name('partidas.show');
    });
    Route::middleware('can:partidas.sync')->group(function () {
        Route::post('/partidas/sync', [PartidaController::class, 'sync'])->name('partidas.sync');
    });

    // Compras. Tablero de reposición de stock: qué hay que comprar y cuánto.
    // Es la única pantalla del panel que manda el dataset entero en las props
    // (~185 KB gzip) en vez de paginar — los KPIs, el TOTAL y el Pareto se
    // calculan sobre todo el conjunto filtrado. Ver ComprasController.
    //
    // La URL tiene que ser /compras a secas: AppLayout::isActive() compara el
    // primer segmento del nombre de ruta contra la URL para marcar el menú.
    Route::middleware('can:compras.view')->group(function () {
        Route::get('/compras', [ComprasController::class, 'index'])->name('compras.index');
    });
    Route::middleware('can:compras.sync')->group(function () {
        Route::post('/compras/sync', [ComprasController::class, 'sync'])->name('compras.sync');
    });

    // Observaciones
    Route::middleware('can:observaciones.view')->group(function () {
        Route::get('/observaciones', [AdminObservacionController::class, 'index'])->name('observaciones.index');
        Route::get('/observaciones/export', [AdminObservacionController::class, 'export'])->name('observaciones.export');
        // Autocompletado para vincular observaciones a un desvío. Va ANTES de
        // /observaciones/{observacion} o "buscar" se resolvería como un id.
        // A diferencia de `articulos.buscar`, éste es interno: pide login y
        // `observaciones.view`.
        Route::get('/observaciones/buscar', [AdminObservacionController::class, 'buscar'])->name('observaciones.buscar');
        // El parámetro se llama {attachment} (no {archivo}) porque scopeBindings
        // busca la relación por el plural del nombre: Observacion::attachments().
        Route::get('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'downloadArchivo'])
            ->name('observaciones.archivos.download')->scopeBindings();
        Route::get('/observaciones/{observacion}/pdf', [AdminObservacionController::class, 'pdf'])
            ->name('observaciones.pdf');
        // Subir y borrar archivos es gestionar el caso: lo autoriza la Policy
        // (solo el responsable asignado), no el permiso global observaciones.edit.
        Route::post('/observaciones/{observacion}/archivos', [AdminObservacionController::class, 'uploadArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.store');
        Route::delete('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'destroyArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.destroy')->scopeBindings();
        // Comentario de bitácora (con adjuntos opcionales). Misma autorización
        // que arriba (solo el responsable): notificados y gente del sector
        // quedan en solo lectura. No hay ruta de edición/borrado — el
        // historial es inmutable.
        Route::post('/observaciones/{observacion}/bitacora', [AdminObservacionController::class, 'comentar'])
            ->middleware('can:update,observacion')->name('observaciones.bitacora.store');
        // Va después de /observaciones/nuevo y /observaciones/crear en el archivo,
        // pero igual se restringe a numérico para que no se las coma.
        // withTrashed(): tiene que poder abrirse desde la pantalla de Bajas.
        Route::get('/observaciones/{observacion}', [AdminObservacionController::class, 'show'])
            ->whereNumber('observacion')->withTrashed()->name('observaciones.show');
        // Editar: solo el responsable asignado (o super-admin, vía Gate::before) — ver ObservacionPolicy.
        // Comentar en la bitácora usa la misma regla, sin excepción para notificados ni sector.
        Route::put('/observaciones/{observacion}', [AdminObservacionController::class, 'update'])
            ->middleware('can:update,observacion')->name('observaciones.update');
    });
    Route::middleware('can:observaciones.edit')->group(function () {
        // Selector "Crear nuevo registro" (interna / No Conformidad). La externa es solo portal público.
        Route::get('/observaciones/nuevo', [AdminObservacionController::class, 'nuevo'])->name('observaciones.nuevo');
        // Carga manual interna: formulario dirigido por taxonomía (sector -> tipo -> datos específicos).
        Route::get('/observaciones/crear', [AdminObservacionController::class, 'create'])->name('observaciones.create');
        Route::post('/observaciones', [AdminObservacionController::class, 'store'])->name('observaciones.store');
    });
    // Borrar (soft delete, con motivo) y la papelera de canceladas/borradas:
    // permiso propio, más grave que observaciones.edit — no lo hereda
    // cualquiera que gestione el caso, solo admin y super-admin.
    Route::middleware('can:observaciones.delete')->group(function () {
        Route::delete('/observaciones/{observacion}', [AdminObservacionController::class, 'destroy'])
            ->name('observaciones.destroy');
        Route::get('/bajas', [BajaController::class, 'index'])->name('bajas.index');
        // withTrashed(): la observación a restaurar está borrada por definición.
        Route::post('/bajas/{observacion}/restaurar', [BajaController::class, 'restore'])
            ->withTrashed()->name('bajas.restore');
    });

    // No Conformidades. Flujo de 7 estados con aprobación previa — ver
    // docs/IT- PARA LA GESTIÓN DE NO CONFORMIDADES EN EL SISTEMA.docx.
    //
    // La URL tiene que empezar con /no-conformidades: AppLayout::isActive()
    // compara el primer segmento del nombre de ruta contra la URL para marcar
    // el menú. Es además la ruta a la que ya apuntaban el FAB y el selector de
    // "Crear nuevo registro", que hasta ahora estaba muerta.
    Route::middleware('can:nc.view')->group(function () {
        Route::get('/no-conformidades', [NoConformidadController::class, 'index'])->name('no-conformidades.index');
        Route::post('/no-conformidades/{noConformidad}/bitacora', [NoConformidadController::class, 'comentar'])
            ->name('no-conformidades.bitacora.store');
    });
    // Escalar una observación a un desvío. Vive acá y no con las rutas de
    // observaciones porque lo que crea es una NC; la Policy de la observación
    // se chequea igual dentro del controller.
    Route::post('/observaciones/{observacion}/derivar-a-nc', [NoConformidadController::class, 'crearDesdeObservacion'])
        ->middleware('can:nc.create')->name('observaciones.derivar-a-nc');
    Route::middleware('can:nc.create')->group(function () {
        // Antes del show para que /crear no caiga en el route-model binding.
        Route::get('/no-conformidades/crear', [NoConformidadController::class, 'create'])->name('no-conformidades.create');
        Route::post('/no-conformidades', [NoConformidadController::class, 'store'])->name('no-conformidades.store');
    });
    Route::middleware('can:nc.view')->group(function () {
        Route::get('/no-conformidades/{noConformidad}', [NoConformidadController::class, 'show'])
            ->whereNumber('noConformidad')->name('no-conformidades.show');
        // El Informe de Desvío con el layout del formulario en papel. Va con
        // `nc.view` y no con un permiso propio: bajarlo es leer el caso.
        Route::get('/no-conformidades/{noConformidad}/pdf', [NoConformidadController::class, 'pdf'])
            ->name('no-conformidades.pdf');
    });
    // Transiciones: cada una gateada por su habilidad de NoConformidadPolicy.
    Route::put('/no-conformidades/{noConformidad}', [NoConformidadController::class, 'update'])
        ->middleware('can:update,noConformidad')->name('no-conformidades.update');
    Route::post('/no-conformidades/{noConformidad}/enviar-a-aprobacion', [NoConformidadController::class, 'enviarAAprobacion'])
        ->middleware('can:enviarAAprobacion,noConformidad')->name('no-conformidades.enviar');
    Route::post('/no-conformidades/{noConformidad}/aprobar', [NoConformidadController::class, 'aprobar'])
        ->middleware('can:aprobar,noConformidad')->name('no-conformidades.aprobar');
    Route::post('/no-conformidades/{noConformidad}/devolver', [NoConformidadController::class, 'devolver'])
        ->middleware('can:aprobar,noConformidad')->name('no-conformidades.devolver');
    Route::post('/no-conformidades/{noConformidad}/rechazar', [NoConformidadController::class, 'rechazar'])
        ->middleware('can:aprobar,noConformidad')->name('no-conformidades.rechazar');

    // ⚠️ **Rutas por RENGLÓN: sin `can:gestionar,noConformidad`.**
    // Ese middleware mira la NC entera y bloquearía al responsable de una acción
    // antes de que el controller llegue a chequear la fila. La autorización la
    // hace el controller contra el renglón (`NonConformityActionPolicy` /
    // `NonConformityContainmentPolicy`), que es lo que permite que quien tiene
    // una acción asignada la gestione sin depender de Calidad.
    // Sin `scopeBindings()` por el plural inglés — ver el comentario de abajo.
    Route::put('/no-conformidades/{noConformidad}/acciones/{accion}', [NoConformidadController::class, 'actualizarAccion'])
        ->name('no-conformidades.acciones.update');
    Route::delete('/no-conformidades/{noConformidad}/acciones/{accion}', [NoConformidadController::class, 'eliminarAccion'])
        ->name('no-conformidades.acciones.destroy');
    Route::post('/no-conformidades/{noConformidad}/acciones/{accion}/avance', [NoConformidadController::class, 'actualizarAvance'])
        ->name('no-conformidades.acciones.avance');
    Route::put('/no-conformidades/{noConformidad}/contencion/{contencion}', [NoConformidadController::class, 'actualizarContencion'])
        ->name('no-conformidades.contencion.update');
    Route::delete('/no-conformidades/{noConformidad}/contencion/{contencion}', [NoConformidadController::class, 'eliminarContencion'])
        ->name('no-conformidades.contencion.destroy');
    // ⚠️ El ALTA de una contención también queda fuera del grupo, aunque sea
    // una ruta de NC y no de renglón: la sección 3 se escribe **antes** de que
    // el desvío esté aprobado, cuando quien lo cargó todavía no es responsable
    // ni tiene `nc.gestionar`. El controller autoriza con `cargarContencion`.
    Route::post('/no-conformidades/{noConformidad}/contencion', [NoConformidadController::class, 'guardarContencion'])
        ->name('no-conformidades.contencion.store');

    // Pasar la posta: la Policy deja al responsable actual derivar el caso sin
    // pasar por Calidad, así que tampoco puede ir bajo `gestionar`.
    Route::post('/no-conformidades/{noConformidad}/responsable', [NoConformidadController::class, 'asignarResponsable'])
        ->middleware('can:asignarResponsable,noConformidad')->name('no-conformidades.responsable');

    // Investigación y plan de acción (§4.4 y §4.5): todo bajo `gestionar`, que
    // ahora es Gestión de Calidad **o el responsable del caso**.
    Route::middleware('can:gestionar,noConformidad')->group(function () {
        Route::put('/no-conformidades/{noConformidad}/investigacion', [NoConformidadController::class, 'guardarInvestigacion'])
            ->name('no-conformidades.investigacion');
        Route::post('/no-conformidades/{noConformidad}/plan-accion', [NoConformidadController::class, 'avanzarAPlanAccion'])
            ->name('no-conformidades.plan-accion');
        // Vincular y desvincular observaciones (§5). Va bajo `gestionar` y NO
        // bajo `update`, que es más angosto y dejaría afuera al responsable del
        // caso — que es justamente quien necesita vincular.
        Route::put('/no-conformidades/{noConformidad}/observaciones', [NoConformidadController::class, 'vincularObservaciones'])
            ->name('no-conformidades.observaciones');
        // Cuándo se va a verificar la eficacia. Se carga con el plan, no en la
        // sección 6: es lo que permite que el recordatorio salga a tiempo.
        Route::put('/no-conformidades/{noConformidad}/verificacion-prevista', [NoConformidadController::class, 'guardarFechaVerificacion'])
            ->name('no-conformidades.verificacion-prevista');
        Route::post('/no-conformidades/{noConformidad}/acciones', [NoConformidadController::class, 'guardarAccion'])
            ->name('no-conformidades.acciones.store');
        // ⚠️ SIN `scopeBindings()`: Laravel resuelve el hijo buscando el método
        // de relación por el **plural inglés** del parámetro de ruta, o sea
        // `accions()`, y la relación se llama `acciones()`. Renombrar una de
        // las dos para que el pluralizador acierte una palabra en español es
        // peor que verificar la pertenencia a mano, que además se lee.
        // El controller hace el `abort_unless`. Mismo problema que el
        // `{attachment}` de los adjuntos de cliente.
        Route::post('/no-conformidades/{noConformidad}/implementacion', [NoConformidadController::class, 'avanzarAImplementacion'])
            ->name('no-conformidades.implementacion');

        Route::post('/no-conformidades/{noConformidad}/verificacion', [NoConformidadController::class, 'avanzarAVerificacion'])
            ->name('no-conformidades.verificacion');
        Route::put('/no-conformidades/{noConformidad}/verificacion', [NoConformidadController::class, 'guardarVerificacion'])
            ->name('no-conformidades.verificacion.guardar');
        Route::post('/no-conformidades/{noConformidad}/cerrar', [NoConformidadController::class, 'cerrar'])
            ->name('no-conformidades.cerrar');
        // "Nuevo desvío N°" (sección 7): abre la NC que reemplaza a ésta cuando
        // la verificación dio ineficaz.
        Route::post('/no-conformidades/{noConformidad}/derivar', [NoConformidadController::class, 'derivar'])
            ->name('no-conformidades.derivar');
    });

    // Reapertura y cancelación: fuera del flujo normal, solo super-admin (la
    // Policy devuelve false para todos y las habilita el bypass de Gate::before).
    Route::post('/no-conformidades/{noConformidad}/reabrir', [NoConformidadController::class, 'reabrir'])
        ->middleware('can:reabrir,noConformidad')->name('no-conformidades.reabrir');
    Route::post('/no-conformidades/{noConformidad}/cancelar', [NoConformidadController::class, 'cancelar'])
        ->middleware('can:cancelar,noConformidad')->name('no-conformidades.cancelar');

    // Roles
    Route::middleware('can:roles.view')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    });
    Route::middleware('can:roles.create')->group(function () {
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    });
    Route::middleware('can:roles.edit')->group(function () {
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::middleware('can:roles.delete')->group(function () {
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // Configuración de marca y textos (logo, favicon, textos del login,
    // del portal y del PDF). Permisos propios: cambiar la identidad de la app
    // es más sensible que administrar usuarios.
    Route::middleware('can:configuracion.view')->group(function () {
        Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
    });
    Route::middleware('can:configuracion.edit')->group(function () {
        Route::post('/configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
    });

    // Bitácora: vista transversal del historial de todas las observaciones
    // (la de un caso puntual vive embebida en Observaciones). Permiso propio y
    // no observaciones.view — ver quién hizo qué en todo el sistema es una
    // capacidad más sensible que ver el listado de casos.
    Route::middleware('can:bitacora.view')->group(function () {
        Route::get('/bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
    });
});
