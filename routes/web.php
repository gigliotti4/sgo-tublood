<?php

use App\Http\Controllers\Admin\ArticuloController as AdminArticuloController;
use App\Http\Controllers\Admin\BajaController;
use App\Http\Controllers\Admin\BitacoraController;
use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\Admin\ComprasController;
use App\Http\Controllers\Admin\ConfiguracionController;
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
        // El parámetro se llama {attachment} (no {archivo}) porque scopeBindings
        // busca la relación por el plural del nombre: Observacion::attachments().
        Route::get('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'downloadArchivo'])
            ->name('observaciones.archivos.download')->scopeBindings();
        Route::get('/observaciones/{observacion}/pdf', [AdminObservacionController::class, 'pdf'])
            ->name('observaciones.pdf');
        // Subir y borrar archivos es gestionar el caso: lo autoriza la Policy
        // (responsable asignado o del sector), no el permiso global observaciones.edit.
        Route::post('/observaciones/{observacion}/archivos', [AdminObservacionController::class, 'uploadArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.store');
        Route::delete('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'destroyArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.destroy')->scopeBindings();
        // Comentario de bitácora (con adjuntos opcionales). Autorización más
        // amplia que la de arriba: también comentan los usuarios sumados como
        // "a notificar" (ObservacionPolicy::comentar). No hay ruta de
        // edición/borrado — el historial es inmutable.
        Route::post('/observaciones/{observacion}/bitacora', [AdminObservacionController::class, 'comentar'])
            ->middleware('can:comentar,observacion')->name('observaciones.bitacora.store');
        // Va después de /observaciones/nuevo y /observaciones/crear en el archivo,
        // pero igual se restringe a numérico para que no se las coma.
        // withTrashed(): tiene que poder abrirse desde la pantalla de Bajas.
        Route::get('/observaciones/{observacion}', [AdminObservacionController::class, 'show'])
            ->whereNumber('observacion')->withTrashed()->name('observaciones.show');
        // Editar: solo el responsable asignado (o super-admin, vía Gate::before) — ver ObservacionPolicy.
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
