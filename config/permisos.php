<?php

/*
|--------------------------------------------------------------------------
| Etiquetas de permisos
|--------------------------------------------------------------------------
|
| Nombre legible y agrupación para cada permiso, solo para mostrar en pantalla
| (tabla de roles y checkboxes del alta/edición).
|
| ⚠️ La clave es el nombre técnico con el que Spatie, el middleware `can:` y
| el composable usePermissions() reconocen el permiso: NO se renombra. Acá se
| traduce únicamente la presentación.
|
| Un permiso sin entrada acá cae de vuelta a su nombre técnico y al grupo
| "Otros", así que agregar permisos nuevos no rompe ninguna pantalla.
|
*/

return [

    'grupos' => [
        'usuarios' => 'Usuarios',
        'roles' => 'Roles y permisos',
        'clientes' => 'Clientes',
        'observaciones' => 'Observaciones',
        'bitacora' => 'Bitácora',
    ],

    'etiquetas' => [
        'users.view' => ['grupo' => 'usuarios', 'label' => 'Ver usuarios'],
        'users.create' => ['grupo' => 'usuarios', 'label' => 'Crear usuarios'],
        'users.edit' => ['grupo' => 'usuarios', 'label' => 'Editar usuarios'],
        'users.delete' => ['grupo' => 'usuarios', 'label' => 'Eliminar usuarios'],

        'roles.view' => ['grupo' => 'roles', 'label' => 'Ver roles'],
        'roles.create' => ['grupo' => 'roles', 'label' => 'Crear roles'],
        'roles.edit' => ['grupo' => 'roles', 'label' => 'Editar roles'],
        'roles.delete' => ['grupo' => 'roles', 'label' => 'Eliminar roles'],
        'permissions.view' => ['grupo' => 'roles', 'label' => 'Ver permisos'],

        'clientes.view' => ['grupo' => 'clientes', 'label' => 'Ver clientes'],
        'clientes.sync' => ['grupo' => 'clientes', 'label' => 'Sincronizar con RP Sistemas'],
        'clientes.edit' => ['grupo' => 'clientes', 'label' => 'Editar clientes'],
        'clientes.vencimientos' => ['grupo' => 'clientes', 'label' => 'Ver avisos de clientes por vencer'],

        'observaciones.view' => ['grupo' => 'observaciones', 'label' => 'Ver observaciones'],
        // Ojo: este permiso ya no gatea la edición de una observación puntual
        // (eso lo decide ObservacionPolicy según el responsable asignado), pero
        // sí habilita la carga manual.
        'observaciones.edit' => ['grupo' => 'observaciones', 'label' => 'Cargar y gestionar observaciones'],

        'bitacora.view' => ['grupo' => 'bitacora', 'label' => 'Ver la bitácora de todas las observaciones'],
    ],

];
