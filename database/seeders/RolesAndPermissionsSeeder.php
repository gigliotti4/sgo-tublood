<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
            'permissions.view',
            'clientes.view', 'clientes.sync', 'clientes.edit', 'clientes.vencimientos', 'clientes.import',
            'observaciones.view', 'observaciones.edit', 'observaciones.delete',
            'bitacora.view',
            'articulos.view', 'articulos.edit', 'articulos.sync', 'articulos.import',
            'proveedores.view', 'proveedores.edit', 'proveedores.import', 'proveedores.sync',
            'ventas.view', 'ventas.sync', 'ventas.montos',
            'partidas.view', 'partidas.sync',
            'compras.view', 'compras.sync',
            'configuracion.view', 'configuracion.edit',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Ojo: `ventas.montos` (los importes del listado de ventas) queda
        // deliberadamente **sin asignar a ningún rol**. Solo lo tiene
        // `super-admin`, que abajo recibe todos. Es el mismo criterio que
        // `clientes.vencimientos`: un permiso propio para algo que mira una
        // sola persona. Para delegarlo alcanza con dárselo a un rol acá, sin
        // tocar código.
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'users.view', 'users.create', 'users.edit',
            'roles.view',
            'clientes.view', 'clientes.sync', 'clientes.edit', 'clientes.import',
            'observaciones.view', 'observaciones.edit', 'observaciones.delete',
            'bitacora.view',
            'articulos.view', 'articulos.edit', 'articulos.sync', 'articulos.import',
            'proveedores.view', 'proveedores.edit', 'proveedores.import', 'proveedores.sync',
            'ventas.view', 'ventas.sync',
            'partidas.view', 'partidas.sync',
            'compras.view', 'compras.sync',
            'configuracion.view', 'configuracion.edit',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions([
            'users.view', 'roles.view', 'clientes.view', 'observaciones.view',
            'articulos.view', 'proveedores.view', 'ventas.view', 'partidas.view',
            'compras.view',
        ]);

        $usuarioInterno = Role::firstOrCreate(['name' => 'usuario_interno']);
        $usuarioInterno->syncPermissions([
            'clientes.view', 'clientes.sync', 'clientes.edit', 'clientes.import',
            'observaciones.view', 'observaciones.edit',
            'articulos.view', 'articulos.edit', 'articulos.sync', 'articulos.import',
            'proveedores.view', 'proveedores.edit', 'proveedores.import', 'proveedores.sync',
            'ventas.view', 'ventas.sync',
            'partidas.view', 'partidas.sync',
            'compras.view', 'compras.sync',
        ]);

        $soloLectura = Role::firstOrCreate(['name' => 'solo_lectura']);
        $soloLectura->syncPermissions(['clientes.view', 'observaciones.view']);

        $garantiaCalidad = Role::firstOrCreate(['name' => 'garantia_calidad']);
        $garantiaCalidad->syncPermissions(['observaciones.view', 'observaciones.edit']);

        // Reparto de los reclamos del portal dentro de Garantía de Calidad: cada
        // rol atiende un tipo, según `incidencias.roles_por_tipo`. Definen a
        // quién le llega el mail del alta y a quién le aparece el reclamo en la
        // campana y en el modal de reclamos nuevos.
        $calidadProducto = Role::firstOrCreate(['name' => 'calidad_producto']);
        $calidadProducto->syncPermissions(['observaciones.view', 'observaciones.edit']);

        // Calidad de Servicio además es quien sigue los vencimientos de clientes.
        $calidadServicio = Role::firstOrCreate(['name' => 'calidad_servicio']);
        $calidadServicio->syncPermissions([
            'observaciones.view', 'observaciones.edit',
            'clientes.view', 'clientes.vencimientos',
        ]);

        Role::firstOrCreate(['name' => 'cliente_externo']);

        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
            ]
        );
        $user->assignRole('super-admin');
    }
}
