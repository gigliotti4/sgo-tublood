<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
            'permissions.view',
            'clientes.view', 'clientes.sync',
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
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions(['users.view', 'roles.view', 'clientes.view']);

        $usuarioInterno = Role::firstOrCreate(['name' => 'usuario_interno']);
        $usuarioInterno->syncPermissions(['clientes.view', 'clientes.sync']);

        $soloLectura = Role::firstOrCreate(['name' => 'solo_lectura']);
        $soloLectura->syncPermissions(['clientes.view']);

        Role::firstOrCreate(['name' => 'garantia_calidad']);
        Role::firstOrCreate(['name' => 'cliente_externo']);

        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name'     => 'Super Admin',
                'password' => 'password',
            ]
        );
        $user->assignRole('super-admin');
    }
}
