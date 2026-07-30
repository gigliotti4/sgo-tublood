<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * La sección "Auditoría" pasó a llamarse "Bitácora" y su permiso también.
 *
 * El seeder crea el permiso nuevo pero no borra el viejo (solo hace
 * firstOrCreate + syncPermissions), así que sin esto `auditoria.view` queda
 * dando vueltas en la base — y ese nombre lo va a necesitar la sección de
 * Auditoría que se construya más adelante, que es otra cosa.
 *
 * Se renombra la fila en vez de borrarla y crear otra: así los roles que ya lo
 * tenían asignado conservan el acceso sin depender de que alguien corra el
 * seeder (aunque deploy.sh ya lo corre en cada deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'auditoria.view')
            ->update(['name' => 'bitacora.view']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'bitacora.view')
            ->update(['name' => 'auditoria.view']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
