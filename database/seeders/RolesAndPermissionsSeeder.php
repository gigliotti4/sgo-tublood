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
            'clientes.view', 'clientes.sync',
            'observaciones.view', 'observaciones.edit',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'users.view', 'users.create', 'users.edit',
            'roles.view',
            'clientes.view', 'clientes.sync',
            'observaciones.view', 'observaciones.edit',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions(['users.view', 'roles.view', 'clientes.view', 'observaciones.view']);

        $usuarioInterno = Role::firstOrCreate(['name' => 'usuario_interno']);
        $usuarioInterno->syncPermissions(['clientes.view', 'clientes.sync', 'observaciones.view', 'observaciones.edit']);

        $soloLectura = Role::firstOrCreate(['name' => 'solo_lectura']);
        $soloLectura->syncPermissions(['clientes.view', 'observaciones.view']);

        $garantiaCalidad = Role::firstOrCreate(['name' => 'garantia_calidad']);
        $garantiaCalidad->syncPermissions(['observaciones.view', 'observaciones.edit']);

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
