<?php

use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\Admin\ObservacionController as AdminObservacionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\Portal\ObservacionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

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
    // Cierre del modal de reclamos externos nuevos (equipo de Garantía de Calidad).
    Route::post('/notificaciones/externas-vistas', [NotificacionController::class, 'marcarExternasVistas'])
        ->name('notificaciones.externas.vistas');

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
        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/{cliente}/archivos/{attachment}', [ClienteController::class, 'downloadArchivo'])
            ->name('clientes.archivos.download')->scopeBindings();
    });
    Route::middleware('can:clientes.sync')->group(function () {
        Route::post('/clientes/sync', [ClienteController::class, 'sync'])->name('clientes.sync');
    });
    Route::middleware('can:clientes.edit')->group(function () {
        Route::get('/clientes/{cliente}/edit', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
        Route::post('/clientes/{cliente}/archivos', [ClienteController::class, 'uploadArchivo'])
            ->name('clientes.archivos.store');
        Route::delete('/clientes/{cliente}/archivos/{attachment}', [ClienteController::class, 'destroyArchivo'])
            ->name('clientes.archivos.destroy')->scopeBindings();
    });

    // Observaciones
    Route::middleware('can:observaciones.view')->group(function () {
        Route::get('/observaciones', [AdminObservacionController::class, 'index'])->name('observaciones.index');
        // El parámetro se llama {attachment} (no {archivo}) porque scopeBindings
        // busca la relación por el plural del nombre: Observacion::attachments().
        Route::get('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'downloadArchivo'])
            ->name('observaciones.archivos.download')->scopeBindings();
        Route::get('/observaciones/{observacion}/pdf', [AdminObservacionController::class, 'pdf'])
            ->name('observaciones.pdf');
        // Subir y borrar archivos es gestionar el caso: lo autoriza la Policy
        // (responsable asignado), no el permiso global observaciones.edit.
        Route::post('/observaciones/{observacion}/archivos', [AdminObservacionController::class, 'uploadArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.store');
        Route::delete('/observaciones/{observacion}/archivos/{attachment}', [AdminObservacionController::class, 'destroyArchivo'])
            ->middleware('can:update,observacion')->name('observaciones.archivos.destroy')->scopeBindings();
        // Va después de /observaciones/nuevo y /observaciones/crear en el archivo,
        // pero igual se restringe a numérico para que no se las coma.
        Route::get('/observaciones/{observacion}', [AdminObservacionController::class, 'show'])
            ->whereNumber('observacion')->name('observaciones.show');
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
});
